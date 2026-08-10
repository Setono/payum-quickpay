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
use Setono\Payum\Quickpay\Api;
use Setono\Payum\Quickpay\Operations;
use Setono\Quickpay\Client\Client;
use Setono\Quickpay\Enum\OperationType;
use Setono\Quickpay\Enum\PaymentState;

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

    public function setUp(): void
    {
        parent::setUp();

        $this->httpClient = new MockHttpClient();

        $this->api = $this->createApi();

        // A real gateway is needed so actions that dispatch sub-requests stay offline:
        // NotifyAction -> GetHttpRequest (StubGetHttpRequestAction) and -> ConfirmPayment.
        $confirmPaymentAction = new ConfirmPaymentAction();
        $confirmPaymentAction->setApi($this->api);

        $this->httpRequestAction = new StubGetHttpRequestAction();

        $gateway = new Gateway();
        $gateway->addApi($this->api);
        $gateway->addAction($confirmPaymentAction);
        $gateway->addAction($this->httpRequestAction);
        $this->gateway = $gateway;
    }

    /**
     * The {@see Api} the tests run against. `$synchronized` mirrors the gateway option of the same
     * name: it is set as the SDK client's client-wide default, so the payment operations append the
     * `?synchronized` flag. The client always wraps the shared mock HTTP client, so responses queued
     * on the test case are served to every Api built here.
     */
    protected function createApi(bool $synchronized = false): Api
    {
        return new Api(
            client: new Client('test-apikey', $this->httpClient, synchronized: $synchronized),
            privateKey: 'test-privatekey',
            orderPrefix: 'ut',
            paymentMethods: 'visa',
            language: 'en',
            autoCapture: true,
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
     * A pending operation — the shape an asynchronous operation has until Quickpay finishes
     * processing it — carries no status code yet, hence the nullable `$statusCode`.
     *
     * @return array<string, mixed>
     */
    protected function operation(OperationType $type, ?string $statusCode = Operations::APPROVED_STATUS_CODE, int $amount = 100, bool $pending = false): array
    {
        return [
            'id' => 1,
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
