<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Tests\Action;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\LogicException;
use Payum\Core\Request\Cancel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Setono\Payum\Quickpay\Action\CancelAction;
use Setono\Payum\Quickpay\Exception\OperationPendingException;
use Setono\Payum\Quickpay\Exception\OperationRejectedException;
use Setono\Quickpay\Enum\OperationType;
use Setono\Quickpay\Enum\PaymentState;
use Setono\Quickpay\Exception\ValidationException;

class CancelActionTest extends ActionTestAbstract
{
    protected static string $requestClass = Cancel::class;

    protected static string $actionClass = CancelAction::class;

    #[Test]
    public function shouldCancelPayment(): void
    {
        $details = new ArrayObject(['quickpayPaymentId' => 1001, 'amount' => 100]);

        /** @var Cancel $cancel */
        $cancel = new static::$requestClass($details);

        $action = new CancelAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        // The fetch (for the in-flight guard and the balance), then the cancel.
        $this->queuePayment([
            'state' => PaymentState::New->value,
            'balance' => 0,
            'operations' => [$this->operation(OperationType::Authorize)],
        ]);
        $this->queuePayment([
            'state' => PaymentState::Processed->value,
            'operations' => [$this->operation(OperationType::Authorize), $this->operation(OperationType::Cancel)],
        ]);

        $action->execute($cancel);

        $requests = $this->getRequests();
        self::assertCount(2, $requests);
        $this->assertRequest($requests[0], 'GET', '#/payments/1001$#');
        $this->assertRequest($requests[1], 'POST', '#/payments/1001/cancel$#');
        // Cancel takes no body: the SDK (>= 1.1) sends an empty JSON object, which the API accepts
        // where a literal empty array would be rejected.
        self::assertSame('{}', (string) $requests[1]->getBody());
        self::assertFalse($requests[1]->hasHeader('QuickPay-Callback-Url'), 'No notify url in the details: Quickpay\'s default callback url applies');
        self::assertSame(0, $details['balance']);
    }

    /**
     * The payment's own notify url is named on the request, so the cancel's callback lands on the same
     * per-payment endpoint as the payment window's instead of the account-wide url.
     */
    #[Test]
    public function shouldRouteTheCallbackToThePaymentsOwnNotifyUrl(): void
    {
        /** @var Cancel $cancel */
        $cancel = new static::$requestClass(new ArrayObject(['quickpayPaymentId' => 1001, 'amount' => 100, 'callback_url' => 'https://shop.example/notify?payum_token=abc']));

        $action = new CancelAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        $this->queuePayment(['state' => PaymentState::New->value, 'operations' => [$this->operation(OperationType::Authorize)]]);
        $this->queuePayment(['state' => PaymentState::Processed->value, 'operations' => [$this->operation(OperationType::Authorize), $this->operation(OperationType::Cancel)]]);

        $action->execute($cancel);

        self::assertSame('https://shop.example/notify?payum_token=abc', $this->getRequests()[1]->getHeaderLine('QuickPay-Callback-Url'));
    }

    /**
     * A model without a quickpayPaymentId has no payment to cancel. The guard throws before any
     * HTTP happens — without it, `(int) null = 0` would reach the API as `POST /payments/0/cancel`.
     */
    #[Test]
    public function shouldThrowWhenThePaymentHasNotBeenCreated(): void
    {
        /** @var Cancel $cancel */
        $cancel = new static::$requestClass(new ArrayObject(['amount' => 100]));

        $action = new CancelAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('quickpayPaymentId');

        try {
            $action->execute($cancel);
        } finally {
            self::assertCount(0, $this->getRequests(), 'No API call may be made for a payment that does not exist yet');
        }
    }

    /**
     * Cancelling an already captured or cancelled payment is a real state conflict, not something to
     * hide: swallowing it would tell a shop it had cancelled a payment whose money is still held. The
     * first message is what a live account returns for that case (2026-08).
     */
    #[Test]
    #[DataProvider('errorMessageProvider')]
    public function shouldLetErrorsSurface(string $body): void
    {
        $details = new ArrayObject(['quickpayPaymentId' => 1001, 'amount' => 100]);

        /** @var Cancel $cancel */
        $cancel = new static::$requestClass($details);

        $action = new CancelAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        $this->queuePayment([
            'state' => PaymentState::Processed->value,
            'operations' => [$this->operation(OperationType::Authorize), $this->operation(OperationType::Capture)],
        ]);
        $this->queueResponse($body, 400);

        $this->expectException(ValidationException::class);

        try {
            $action->execute($cancel);
        } finally {
            self::assertCount(2, $this->getRequests());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function errorMessageProvider(): iterable
    {
        yield 'invalid state, live wording' => ['{"message":"Validation error: Payment is not in a valid state for cancel","errors":{},"error_code":null}'];
        yield 'older invalid-state wording' => ['{"message":"Transaction in wrong state for this operation"}'];
        yield 'any other error' => ['{"message":"Some other error"}'];
        yield 'no message at all' => ['{}'];
    }

    /**
     * Synchronized, a declined cancel comes back as a 2xx with the completed operation not approved.
     * The action reads that outcome and throws.
     */
    #[Test]
    public function shouldThrowWhenASynchronizedCancelIsDeclined(): void
    {
        /** @var Cancel $cancel */
        $cancel = new static::$requestClass(new ArrayObject(['quickpayPaymentId' => 1001, 'amount' => 100]));

        $action = new CancelAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->createApi(synchronized: true));

        $this->queuePayment([
            'state' => PaymentState::New->value,
            'operations' => [$this->operation(OperationType::Authorize)],
        ]);
        $this->queuePayment([
            'state' => PaymentState::New->value,
            'operations' => [
                $this->operation(OperationType::Authorize),
                ['id' => 2, 'type' => 'cancel', 'amount' => 100, 'pending' => false, 'qp_status_code' => '40000', 'qp_status_msg' => null],
            ],
        ]);

        try {
            $action->execute($cancel);
            self::fail('Expected the decline to surface');
        } catch (OperationRejectedException $e) {
            self::assertSame('Quickpay declined the cancel of payment 1001: status 40000.', $e->getMessage());
            self::assertSame(OperationType::Cancel, $e->getOperation()->type());
        }

        self::assertSame('synchronized', $this->getRequests()[1]->getUri()->getQuery());
    }

    /**
     * Asynchronously the returned payment carries the cancel as pending — no outcome yet, not a decline.
     */
    #[Test]
    public function shouldNotMistakeAPendingCancelForADecline(): void
    {
        /** @var Cancel $cancel */
        $cancel = new static::$requestClass(new ArrayObject(['quickpayPaymentId' => 1001, 'amount' => 100]));

        $action = new CancelAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        $this->queuePayment([
            'state' => PaymentState::New->value,
            'operations' => [$this->operation(OperationType::Authorize)],
        ]);
        $this->queuePayment([
            'state' => PaymentState::New->value,
            'operations' => [
                $this->operation(OperationType::Authorize),
                $this->operation(OperationType::Cancel, null, pending: true),
            ],
        ]);

        $action->execute($cancel);

        self::assertCount(2, $this->getRequests());
    }

    /**
     * One money operation at a time: a capture, refund or cancel still in flight has not settled, and
     * a cancel on top would race it. Nothing is issued.
     *
     * @param list<array<string, mixed>> $operations
     */
    #[Test]
    #[DataProvider('inFlightProvider')]
    public function shouldRefuseWhileAnotherOperationIsInFlight(array $operations, string $expectedType): void
    {
        /** @var Cancel $cancel */
        $cancel = new static::$requestClass(new ArrayObject(['quickpayPaymentId' => 1001, 'amount' => 100]));

        $action = new CancelAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        $this->queuePayment(['state' => PaymentState::New->value, 'operations' => $operations]);

        try {
            $action->execute($cancel);
            self::fail('Expected the in-flight guard to fire');
        } catch (OperationPendingException $e) {
            self::assertStringContainsString(sprintf('A %s of Quickpay payment 1001 is still pending', $expectedType), $e->getMessage());
        }

        self::assertCount(1, $this->getRequests(), 'Only the fetch — nothing may be issued');
    }

    /**
     * @return iterable<string, array{list<array<string, mixed>>, string}>
     */
    public static function inFlightProvider(): iterable
    {
        $authorize = ['id' => 1, 'type' => 'authorize', 'amount' => 100, 'pending' => false, 'qp_status_code' => '20000'];

        yield 'capture pending' => [[$authorize, ['id' => 2, 'type' => 'capture', 'amount' => 100, 'pending' => true, 'qp_status_code' => null]], 'capture'];
        yield 'cancel pending (a retry)' => [[$authorize, ['id' => 2, 'type' => 'cancel', 'amount' => 100, 'pending' => true, 'qp_status_code' => null]], 'cancel'];
    }
}
