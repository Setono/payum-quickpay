<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Tests;

use GuzzleHttp\Psr7\Response;
use Http\Mock\Client as MockHttpClient;
use Payum\Core\Gateway;
use Payum\Core\GatewayInterface;
use Payum\Core\Model\Payment;
use Psr\Http\Message\RequestInterface;
use Setono\Payum\Quickpay\Action\Api\ConfirmPaymentAction;
use Setono\Payum\Quickpay\Action\Api\CreatePaymentLinkAction;
use Setono\Payum\Quickpay\Api;
use Setono\Quickpay\Client\Client;
use Setono\Quickpay\Enum\OperationType;
use Setono\Quickpay\Enum\PaymentState;
use Setono\Quickpay\Response\Payment\Operation;

/**
 * Shared setup for tests exercising the actions and the {@see Api}. The SDK client is built around a
 * PSR-18 {@see MockHttpClient}, so no test touches the live Quickpay API: each test queues the
 * responses the API would return, in the order the code under test performs the requests, and may
 * assert on the recorded requests via {@see self::getRequests()}.
 */
trait ApiTestTrait
{
    protected MockHttpClient $httpClient;

    protected Api $api;

    protected GatewayInterface $gateway;

    protected StubGetHttpRequestAction $httpRequestAction;

    protected StubTokenFactory $tokenFactory;

    /** Quickpay numbers operations per payment from 1; the fixture builder does the same. */
    private int $nextOperationId = 1;

    public function setUp(): void
    {
        parent::setUp();

        $this->nextOperationId = 1;
        $this->httpClient = new MockHttpClient();

        $this->api = $this->createApi();

        // A real gateway is needed so actions that dispatch sub-requests stay offline:
        // NotifyAction -> GetHttpRequest (StubGetHttpRequestAction) and -> ConfirmPayment;
        // AuthorizeAction / CaptureAction -> CreatePaymentLink. The gateway injects the api into
        // ApiAware actions itself; the token factory (an extension's job in a real gateway) is set
        // directly on the one action that needs it.
        $this->httpRequestAction = new StubGetHttpRequestAction();
        $this->tokenFactory = new StubTokenFactory();

        $createPaymentLinkAction = new CreatePaymentLinkAction();
        $createPaymentLinkAction->setGenericTokenFactory($this->tokenFactory);

        $gateway = new Gateway();
        $gateway->addApi($this->api);
        $gateway->addAction(new ConfirmPaymentAction());
        $gateway->addAction($createPaymentLinkAction);
        $gateway->addAction($this->httpRequestAction);
        $this->gateway = $gateway;
    }

    /**
     * The {@see Api} the tests run against. `$synchronized` mirrors the gateway option of the same
     * name: it is set as the SDK client's client-wide default, so the payment operations append the
     * `?synchronized` flag. The client always wraps the shared mock HTTP client, so responses queued
     * on the test case are served to every Api built here.
     */
    protected function createApi(bool $synchronized = false, bool $autoCapture = true): Api
    {
        return new Api(
            client: new Client('test-apikey', $this->httpClient, synchronized: $synchronized),
            privateKey: 'test-privatekey',
            orderPrefix: 'ut',
            paymentMethods: 'visa',
            language: 'en',
            autoCapture: $autoCapture,
            agreementId: 266017,
        );
    }

    protected function queueResponse(string $body, int $status = 200): void
    {
        $this->httpClient->addResponse(new Response($status, [], $body));
    }

    /**
     * Queues a Quickpay payment JSON response built from sensible defaults plus the given overrides.
     *
     * @param array<string, mixed> $overrides
     */
    protected function queuePayment(array $overrides = []): void
    {
        $this->queueResponse($this->paymentJson($overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function paymentJson(array $overrides = []): string
    {
        return (string) json_encode(array_replace([
            'id' => 1001,
            'order_id' => 'ut0001',
            'currency' => 'DKK',
            'merchant_id' => 75015,
            'accepted' => true,
            'test_mode' => true,
            'state' => PaymentState::Initial->value,
            'fee' => null,
            'operations' => [],
        ], $overrides), \JSON_THROW_ON_ERROR);
    }

    /**
     * The `link` object nested on a payment, as Quickpay returns it after `PUT /payments/{id}/link`.
     * `auto_capture` is the flag CaptureAction reads to decide whether Quickpay captures by itself.
     *
     * @return array<string, mixed>
     */
    protected function link(bool $autoCapture): array
    {
        return [
            'url' => 'https://payment.quickpay.net/payments/1001/window',
            'amount' => 100,
            'auto_capture' => $autoCapture,
        ];
    }

    /**
     * A pending operation — the shape an asynchronous operation has until Quickpay finishes
     * processing it — carries no status code yet, hence the nullable `$statusCode`.
     *
     * Ids increase in the order operations are built, as Quickpay numbers them: "the latest
     * operation" is the one with the highest id (the SDK's definition), so a fixture that hands
     * every operation the same id would make that decision accidental.
     *
     * @return array<string, mixed>
     */
    protected function operation(OperationType $type, ?string $statusCode = Operation::QP_STATUS_APPROVED, int $amount = 100, bool $pending = false): array
    {
        return [
            'id' => $this->nextOperationId++,
            'type' => $type->value,
            'amount' => $amount,
            'pending' => $pending,
            'qp_status_code' => $statusCode,
        ];
    }

    protected function createPayment(): Payment
    {
        $payment = new Payment();
        $payment->setNumber('000000000001');
        $payment->setTotalAmount(100);
        $payment->setCurrencyCode('DKK');

        return $payment;
    }

    /**
     * @return list<RequestInterface>
     */
    protected function getRequests(): array
    {
        return $this->httpClient->getRequests();
    }

    /**
     * Asserts the method, path and the auth/version headers the SDK client sets on every request.
     */
    protected function assertRequest(RequestInterface $request, string $method, string $pathPattern): void
    {
        self::assertSame($method, $request->getMethod());
        self::assertMatchesRegularExpression($pathPattern, $request->getUri()->getPath());
        self::assertSame('Basic ' . base64_encode(':test-apikey'), $request->getHeaderLine('Authorization'));
        self::assertSame('v10', $request->getHeaderLine('Accept-Version'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeBody(RequestInterface $request): array
    {
        $body = (string) $request->getBody();
        if ('' === $body) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
