<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Tests;

use DateTime;
use GuzzleHttp\Psr7\Response;
use Http\Message\MessageFactory\GuzzleMessageFactory;
use Payum\Core\Gateway;
use Payum\Core\GatewayInterface;
use Payum\Core\Model\Payment;
use Setono\Payum\QuickPay\Action\Api\ConfirmPaymentAction;
use Setono\Payum\QuickPay\Api;
use Setono\Payum\QuickPay\Model\QuickpayCard;
use Setono\Payum\QuickPay\Model\QuickPayPayment;
use Setono\Payum\QuickPay\Model\QuickPayPaymentOperation;

/**
 * Shared setup for tests that exercise the {@see Api} and the actions. The Api is built around a
 * {@see StubHttpClient}, so no test touches the live QuickPay API: each test queues the responses
 * the QuickPay API would return for the requests it triggers.
 */
trait ApiTestTrait
{
    protected StubHttpClient $httpClient;

    protected Api $api;

    protected GatewayInterface $gateway;

    public function setUp(): void
    {
        parent::setUp();

        $this->httpClient = new StubHttpClient();
        $this->api = new Api($this->apiOptions(), $this->httpClient, new GuzzleMessageFactory());

        // A real gateway is only needed so actions that dispatch sub-requests (NotifyAction ->
        // ConfirmPayment) stay offline; it shares the same stubbed Api.
        $gateway = new Gateway();
        $gateway->addApi($this->api);
        $gateway->addAction(new ConfirmPaymentAction());
        $this->gateway = $gateway;
    }

    /**
     * @return array<string, mixed>
     */
    protected function apiOptions(): array
    {
        return [
            'apikey' => 'test-apikey',
            'privatekey' => 'test-privatekey',
            'merchant' => '75015',
            'agreement' => '266017',
            'order_prefix' => 'ut',
            'payment_methods' => 'visa',
            'auto_capture' => '1',
            'language' => 'en',
        ];
    }

    protected function queueResponse(string $body, int $status = 200): void
    {
        $this->httpClient->addResponse(new Response($status, [], $body));
    }

    /**
     * Queues a QuickPay payment JSON response built from sensible defaults plus the given overrides.
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
            'fee' => null,
            'state' => QuickPayPayment::STATE_INITIAL,
            'operations' => [],
        ], $overrides), \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    protected function operation(string $type, int $statusCode = QuickPayPaymentOperation::STATUS_CODE_APPROVED, int $amount = 100): array
    {
        return [
            'id' => 1,
            'type' => $type,
            'amount' => $amount,
            'qp_status_code' => (string) $statusCode,
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

    protected function getTestCard(): QuickpayCard
    {
        return QuickpayCard::createFromArray([
            'number' => 1000000000000008,
            'expiration' => (new DateTime())->format('ym'),
            'cvd' => 123,
        ]);
    }

    protected function getAuthorizeRejectedTestCard(): QuickpayCard
    {
        $card = $this->getTestCard();
        $card->setNumber($card->getNumber() + 8);

        return $card;
    }

    protected function getCaptureRejectedTestCard(): QuickpayCard
    {
        $card = $this->getTestCard();
        $card->setNumber($card->getNumber() + 24);

        return $card;
    }
}
