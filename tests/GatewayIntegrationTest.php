<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Tests;

use GuzzleHttp\Psr7\Response;
use Http\Mock\Client as MockHttpClient;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\GatewayInterface;
use Payum\Core\Model\Payment;
use Payum\Core\Model\Token;
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Request\Authorize;
use Payum\Core\Request\Convert;
use Payum\Core\Request\GetHumanStatus;
use Payum\Core\Request\Notify;
use PHPUnit\Framework\TestCase;
use Setono\Payum\Quickpay\QuickpayGatewayFactory;
use Setono\Quickpay\Callback\CallbackValidator;
use Setono\Quickpay\Client\Client;
use Setono\Quickpay\Enum\OperationType;
use Setono\Quickpay\Enum\PaymentState;

/**
 * Exercises the gateway exactly as {@see QuickpayGatewayFactory} wires it — every action registered
 * by the factory, apis injected by payum's own machinery, sub-requests (GetHttpRequest,
 * ConfirmPayment) routed through the real gateway. The action tests construct their subjects by
 * hand, so a mis-registered action or a broken aware-interface hookup would slip past them; this is
 * the test that catches it.
 *
 * The HTTP seam is the same as everywhere else: the SDK client wraps a mock PSR-18 client via the
 * `quickpay.client` option. The core gateway factory's default (plain-PHP, superglobal-reading)
 * GetHttpRequest action is replaced with the stub by passing the config key — the same override
 * mechanism `addCoreGatewayFactoryConfig()` uses.
 */
final class GatewayIntegrationTest extends TestCase
{
    private MockHttpClient $httpClient;

    private StubGetHttpRequestAction $httpRequestAction;

    private GatewayInterface $gateway;

    protected function setUp(): void
    {
        $this->httpClient = new MockHttpClient();
        $this->httpRequestAction = new StubGetHttpRequestAction();

        $this->gateway = (new QuickpayGatewayFactory())->create([
            'api_key' => 'integration-apikey',
            'private_key' => 'integration-privatekey',
            'order_prefix' => 'it',
            'auto_capture' => true,
            'quickpay.client' => new Client('integration-apikey', $this->httpClient),
            'payum.action.get_http_request' => $this->httpRequestAction,
        ]);
    }

    /**
     * Convert → Authorize → Notify (signed, auto-captures) → GetStatus, all through the
     * factory-built gateway, fully offline.
     *
     * @test
     */
    public function shouldRunTheWholeFlowThroughTheFactoryWiredGateway(): void
    {
        // -- Convert: creates the payment at Quickpay and produces the scalar details.
        $payment = new Payment();
        $payment->setNumber('000000000001');
        $payment->setTotalAmount(100);
        $payment->setCurrencyCode('DKK');

        $token = new Token();
        $token->setAfterUrl('https://shop.example/after');
        $token->setGatewayName(QuickpayGatewayFactory::NAME);

        $this->queuePayment(['id' => 2002, 'order_id' => 'it000000000001']);

        $convert = new Convert($payment, 'array', $token);
        $this->gateway->execute($convert);

        /** @var array<string, mixed> $result */
        $result = $convert->getResult();
        $details = new ArrayObject($result);

        self::assertSame(2002, $details['quickpayPaymentId']);
        self::assertSame('https://shop.example/after', $details['continue_url']);

        // -- Authorize: creates the payment link and redirects to the payment window. The callback
        // url is preset, so no token factory is involved.
        $details['callback_url'] = 'https://shop.example/notify';

        $this->queueResponse('{"url":"https://payment.quickpay.net/payments/2002/window"}');

        try {
            $this->gateway->execute(new Authorize($details));
            self::fail('An HttpRedirect reply should have been thrown');
        } catch (HttpRedirect $redirect) {
            self::assertSame('https://payment.quickpay.net/payments/2002/window', $redirect->getUrl());
        }

        // -- Notify: a signed authorize callback. auto_capture is on and the amount matches, so the
        // gateway's own ConfirmPayment routing captures.
        $body = '{"id":2002}';
        $this->httpRequestAction->setHttpRequest($body, [
            CallbackValidator::CHECKSUM_HEADER => hash_hmac('sha256', $body, 'integration-privatekey'),
        ]);

        $this->queuePayment([
            'id' => 2002,
            'order_id' => 'it000000000001',
            'state' => PaymentState::New->value,
            'operations' => [$this->operation(OperationType::Authorize, amount: 100)],
        ]);
        $this->queuePayment([
            'id' => 2002,
            'order_id' => 'it000000000001',
            'state' => PaymentState::Processed->value,
            'operations' => [
                $this->operation(OperationType::Authorize, amount: 100),
                $this->operation(OperationType::Capture, amount: 100),
            ],
        ]);

        $this->gateway->execute(new Notify($details));

        // -- GetStatus: the captured payment reports as captured, and the balance is persisted.
        $this->queuePayment([
            'id' => 2002,
            'order_id' => 'it000000000001',
            'state' => PaymentState::Processed->value,
            'balance' => 100,
            'operations' => [
                $this->operation(OperationType::Authorize, amount: 100),
                $this->operation(OperationType::Capture, amount: 100),
            ],
        ]);

        $status = new GetHumanStatus($details);
        $this->gateway->execute($status);

        self::assertTrue($status->isCaptured(), 'The payment should report as captured');
        self::assertSame(100, $details['balance']);

        // -- The wire log: exactly the calls the flow implies, in order, authenticated with the
        // integration credentials.
        $requests = $this->httpClient->getRequests();
        self::assertCount(5, $requests);

        $expected = [
            ['POST', '#/payments$#'],
            ['PUT', '#/payments/2002/link$#'],
            ['GET', '#/payments/2002$#'],
            ['POST', '#/payments/2002/capture$#'],
            ['GET', '#/payments/2002$#'],
        ];

        foreach ($expected as $i => [$method, $pathPattern]) {
            self::assertSame($method, $requests[$i]->getMethod(), sprintf('Request #%d', $i));
            self::assertMatchesRegularExpression($pathPattern, $requests[$i]->getUri()->getPath(), sprintf('Request #%d', $i));
            self::assertSame(
                'Basic ' . base64_encode(':integration-apikey'),
                $requests[$i]->getHeaderLine('Authorization'),
                sprintf('Request #%d', $i),
            );
        }
    }

    private function queueResponse(string $body): void
    {
        $this->httpClient->addResponse(new Response(200, [], $body));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function queuePayment(array $overrides = []): void
    {
        $this->queueResponse((string) json_encode(array_replace([
            'id' => 2002,
            'order_id' => 'it000000000001',
            'currency' => 'DKK',
            'merchant_id' => 75015,
            'accepted' => true,
            'test_mode' => true,
            'state' => PaymentState::Initial->value,
            'fee' => null,
            'operations' => [],
        ], $overrides), \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function operation(OperationType $type, int $amount): array
    {
        return [
            'id' => 1,
            'type' => $type->value,
            'amount' => $amount,
            'pending' => false,
            'qp_status_code' => '20000',
        ];
    }
}
