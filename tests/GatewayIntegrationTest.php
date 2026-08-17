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
use Payum\Core\Request\Capture;
use Payum\Core\Request\Convert;
use Payum\Core\Request\GetHumanStatus;
use Payum\Core\Request\Notify;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
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
            'auto_capture' => false,
            'quickpay.client' => new Client('integration-apikey', $this->httpClient),
            'payum.action.get_http_request' => $this->httpRequestAction,
        ]);
    }

    /**
     * The Payum-idiomatic checkout, exactly as Payum's capture controller and Sylius drive it:
     * Convert → Capture (fresh payment: hosted window, auto-capturing) → Quickpay's callback →
     * Capture again (the customer is back at the token url; the payment is being captured by
     * Quickpay, so this must be a no-op) → GetStatus. All through the factory-built gateway, offline.
     *
     * @test
     */
    public function shouldRunTheCaptureDrivenCheckoutThroughTheFactoryWiredGateway(): void
    {
        // -- Convert: creates the payment at Quickpay and produces the scalar details.
        $payment = new Payment();
        $payment->setNumber('000000000001');
        $payment->setTotalAmount(100);
        $payment->setCurrencyCode('DKK');

        $token = new Token();
        $token->setTargetUrl('https://shop.example/capture?payum_token=cap');
        $token->setAfterUrl('https://shop.example/after');
        $token->setGatewayName(QuickpayGatewayFactory::NAME);

        $this->queuePayment(['id' => 2002, 'order_id' => 'it000000000001']);

        $convert = new Convert($payment, 'array', $token);
        $this->gateway->execute($convert);

        /** @var array<string, mixed> $result */
        $result = $convert->getResult();
        $details = new ArrayObject($result);

        self::assertSame(2002, $details['quickpayPaymentId']);
        self::assertSame('https://shop.example/capture?payum_token=cap', $details['continue_url'], 'The customer returns to the token url');
        self::assertSame('https://shop.example/after', $details['cancel_url']);

        // -- Capture #1, fresh payment: the interactive entry point. The link is created with
        // auto_capture and the customer is redirected. The callback url is preset (no token factory
        // is wired into this bare gateway).
        $details['callback_url'] = 'https://shop.example/notify';

        $this->queuePayment(['id' => 2002, 'order_id' => 'it000000000001', 'state' => PaymentState::Initial->value]);
        $this->queueResponse('{"url":"https://payment.quickpay.net/payments/2002/window"}');

        try {
            $this->gateway->execute(new Capture($details));
            self::fail('An HttpRedirect reply should have been thrown');
        } catch (HttpRedirect $redirect) {
            self::assertSame('https://payment.quickpay.net/payments/2002/window', $redirect->getUrl());
        }

        // -- Notify: Quickpay's signed callback once the customer paid. With auto_capture on the link,
        // Quickpay authorized AND captured; the gateway only refreshes its snapshot.
        $body = '{"id":2002}';
        $this->httpRequestAction->setHttpRequest($body, [
            CallbackValidator::CHECKSUM_HEADER => hash_hmac('sha256', $body, 'integration-privatekey'),
        ]);

        $captured = [
            'id' => 2002,
            'order_id' => 'it000000000001',
            'state' => PaymentState::Processed->value,
            'balance' => 100,
            'operations' => [
                $this->operation(OperationType::Authorize, amount: 100),
                $this->operation(OperationType::Capture, amount: 100),
            ],
            'link' => ['url' => 'https://payment.quickpay.net/payments/2002/window', 'amount' => 100, 'auto_capture' => true],
        ];
        $this->queuePayment($captured);

        $this->gateway->execute(new Notify($details));

        self::assertSame(100, $details['balance']);
        self::assertSame(PaymentState::Processed->value, $details['state']);

        // -- Capture #2, the return trip: Payum re-executes the Capture that sent the customer out.
        // Quickpay captured through the link, so this must not capture again.
        $this->queuePayment($captured);

        $this->gateway->execute(new Capture($details));

        // -- GetStatus: captured.
        $this->queuePayment($captured);

        $status = new GetHumanStatus($details);
        $this->gateway->execute($status);

        self::assertTrue($status->isCaptured(), 'The payment should report as captured');

        // -- The wire log: exactly the calls the flow implies, in order, authenticated with the
        // integration credentials — and NOT ONE capture issued by the gateway itself.
        $requests = $this->httpClient->getRequests();
        self::assertCount(6, $requests);

        $expected = [
            ['POST', '#/payments$#'],           // Convert
            ['GET', '#/payments/2002$#'],       // Capture #1: where is the payment?
            ['PUT', '#/payments/2002/link$#'],  // Capture #1: the link
            ['GET', '#/payments/2002$#'],       // Notify → ConfirmPayment
            ['GET', '#/payments/2002$#'],       // Capture #2: return trip, no-op
            ['GET', '#/payments/2002$#'],       // GetStatus
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

        self::assertTrue($this->decodeBody($requests[2])['auto_capture'], 'Capture-driven: the link captures at authorization');
    }

    /**
     * The other flow: Authorize (auth-only window), then a later Capture through the API — the
     * shape of a shop that settles when it ships.
     *
     * @test
     */
    public function shouldRunTheAuthorizeThenCaptureFlowThroughTheFactoryWiredGateway(): void
    {
        $details = new ArrayObject([
            'quickpayPaymentId' => 2002,
            'amount' => 100,
            'continue_url' => 'https://shop.example/authorize?payum_token=auth',
            'cancel_url' => 'https://shop.example/after',
            'callback_url' => 'https://shop.example/notify',
        ]);

        // -- Authorize #1: fresh → auth-only link (the gateway option is off) → redirect.
        $this->queuePayment(['id' => 2002, 'state' => PaymentState::Initial->value]);
        $this->queueResponse('{"url":"https://payment.quickpay.net/payments/2002/window"}');

        try {
            $this->gateway->execute(new Authorize($details));
            self::fail('An HttpRedirect reply should have been thrown');
        } catch (HttpRedirect) {
        }

        $authorized = [
            'id' => 2002,
            'state' => PaymentState::New->value,
            'balance' => 0,
            'operations' => [$this->operation(OperationType::Authorize, amount: 100)],
            'link' => ['url' => 'https://payment.quickpay.net/payments/2002/window', 'amount' => 100, 'auto_capture' => false],
        ];

        // -- Authorize #2, the return trip: authorized → no-op.
        $this->queuePayment($authorized);
        $this->gateway->execute(new Authorize($details));

        // -- Later: Capture settles through the API.
        $this->queuePayment($authorized);
        $this->queuePayment(['id' => 2002, 'state' => PaymentState::Processed->value, 'balance' => 100, 'operations' => [$this->operation(OperationType::Capture, amount: 100)]]);
        $this->gateway->execute(new Capture($details));

        $requests = $this->httpClient->getRequests();
        self::assertCount(5, $requests);
        self::assertSame('PUT', $requests[1]->getMethod());
        self::assertFalse($this->decodeBody($requests[1])['auto_capture'], 'Authorize: an auth-only link');
        self::assertSame('GET', $requests[2]->getMethod(), 'Return trip: only a fetch');
        self::assertSame('POST', $requests[4]->getMethod());
        self::assertMatchesRegularExpression('#/payments/2002/capture$#', $requests[4]->getUri()->getPath());
        self::assertSame(100, $this->decodeBody($requests[4])['amount']);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(RequestInterface $request): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $request->getBody(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
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
