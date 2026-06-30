<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Tests\Action\Api;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\LogicException;
use PHPUnit\Framework\TestCase;
use Setono\Payum\QuickPay\Action\Api\ConfirmPaymentAction;
use Setono\Payum\QuickPay\Api;
use Setono\Payum\QuickPay\Request\Api\ConfirmPayment;
use Setono\Payum\QuickPay\Tests\ApiTestTrait;
use Setono\Quickpay\Client\Client;
use Setono\Quickpay\Enum\OperationType;
use Setono\Quickpay\Enum\PaymentState;

class ConfirmPaymentActionTest extends TestCase
{
    use ApiTestTrait;

    /**
     * @test
     */
    public function shouldThrowWhenPaymentHasNotBeenCreated(): void
    {
        $action = $this->action($this->api);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The payment has not been created');

        $action->execute(new ConfirmPayment(new ArrayObject(['amount' => 100])));
    }

    /**
     * @test
     */
    public function shouldThrowWhenThereIsNoLatestOperation(): void
    {
        $this->queuePayment(['state' => PaymentState::Initial->value, 'operations' => []]);

        $action = $this->action($this->api);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('latest operation');

        $action->execute(new ConfirmPayment(new ArrayObject(['quickpayPaymentId' => 1001, 'amount' => 100])));
    }

    /**
     * @test
     */
    public function shouldCaptureWhenAutoCaptureAndAmountMatches(): void
    {
        $this->queuePayment([
            'state' => PaymentState::New->value,
            'operations' => [$this->operation(OperationType::Authorize, amount: 100)],
        ]);
        $this->queuePayment([
            'state' => PaymentState::Processed->value,
            'operations' => [$this->operation(OperationType::Capture)],
        ]);

        $action = $this->action($this->api);
        $action->execute(new ConfirmPayment(new ArrayObject(['quickpayPaymentId' => 1001, 'amount' => 100])));

        $requests = $this->getRequests();
        self::assertCount(2, $requests);
        $this->assertRequest($requests[0], 'GET', '#/payments/1001$#');
        $this->assertRequest($requests[1], 'POST', '#/payments/1001/capture$#');
        self::assertSame(100, $this->decodeBody($requests[1])['amount']);
    }

    /**
     * @test
     */
    public function shouldThrowWhenAuthorizedAmountDoesNotMatch(): void
    {
        $this->queuePayment([
            'state' => PaymentState::New->value,
            'operations' => [$this->operation(OperationType::Authorize, amount: 100)],
        ]);

        $action = $this->action($this->api);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Authorized amount does not match');

        $action->execute(new ConfirmPayment(new ArrayObject(['quickpayPaymentId' => 1001, 'amount' => 99])));
    }

    /**
     * @test
     */
    public function shouldNotCaptureWhenAutoCaptureDisabled(): void
    {
        $api = new Api(
            client: new Client('test-apikey', $this->httpClient),
            privateKey: 'test-privatekey',
            autoCapture: false,
        );

        $this->queuePayment([
            'state' => PaymentState::New->value,
            'operations' => [$this->operation(OperationType::Authorize, amount: 100)],
        ]);

        $action = $this->action($api);
        $action->execute(new ConfirmPayment(new ArrayObject(['quickpayPaymentId' => 1001, 'amount' => 100])));

        // Only the reload happened, no capture.
        self::assertCount(1, $this->getRequests());
    }

    /**
     * @test
     */
    public function shouldNotCaptureWhenLatestOperationIsNotAnAuthorize(): void
    {
        $this->queuePayment([
            'state' => PaymentState::Processed->value,
            'operations' => [$this->operation(OperationType::Capture, amount: 100)],
        ]);

        $action = $this->action($this->api);
        $action->execute(new ConfirmPayment(new ArrayObject(['quickpayPaymentId' => 1001, 'amount' => 100])));

        self::assertCount(1, $this->getRequests());
    }

    private function action(Api $api): ConfirmPaymentAction
    {
        $action = new ConfirmPaymentAction();
        $action->setApi($api);
        $action->setGateway($this->gateway);

        return $action;
    }
}
