<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Tests\Action;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\LogicException;
use Payum\Core\Request\Refund;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Setono\Payum\Quickpay\Action\RefundAction;
use Setono\Payum\Quickpay\Exception\OperationRejectedException;
use Setono\Quickpay\Enum\OperationType;
use Setono\Quickpay\Enum\PaymentState;
use Setono\Quickpay\Exception\ValidationException;

class RefundActionTest extends ActionTestAbstract
{
    protected static string $requestClass = Refund::class;

    protected static string $actionClass = RefundAction::class;

    /**
     * A model without a quickpayPaymentId has no payment to refund. The guard throws before any
     * HTTP happens — without it, `(int) null = 0` would reach the API as a request on payment 0.
     */
    #[Test]
    public function shouldThrowWhenThePaymentHasNotBeenCreated(): void
    {
        /** @var Refund $refund */
        $refund = new static::$requestClass(new ArrayObject(['amount' => 100]));

        $action = new RefundAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('quickpayPaymentId');

        try {
            $action->execute($refund);
        } finally {
            self::assertCount(0, $this->getRequests(), 'No API call may be made for a payment that does not exist yet');
        }
    }

    /**
     * With no explicit amount, a refund is for whatever is still refundable — the balance. Defaulting
     * to the payment's full `amount` would be rejected outright the moment anything had already been
     * refunded, since a payment is refundable only up to what is captured.
     */
    #[Test]
    public function shouldRefundTheRemainingBalanceByDefault(): void
    {
        $details = new ArrayObject(['quickpayPaymentId' => 1001, 'amount' => 1000]);

        /** @var Refund $refund */
        $refund = new static::$requestClass($details);

        $action = new RefundAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        // 250 of the 1000 was already refunded elsewhere, so only 750 is refundable.
        $this->queuePayment([
            'state' => PaymentState::Processed->value,
            'balance' => 750,
            'operations' => [$this->operation(OperationType::Capture, amount: 1000)],
        ]);
        $this->queuePayment([
            'state' => PaymentState::Processed->value,
            'balance' => 0,
            'operations' => [$this->operation(OperationType::Refund, amount: 750)],
        ]);

        $action->execute($refund);

        $requests = $this->getRequests();
        self::assertCount(2, $requests, 'The default path fetches the payment, then refunds');
        $this->assertRequest($requests[0], 'GET', '#/payments/1001$#');
        $this->assertRequest($requests[1], 'POST', '#/payments/1001/refund$#');
        self::assertSame(750, $this->decodeBody($requests[1])['amount']);

        self::assertSame(750, $details['balance'], 'The fetched balance is persisted for the caller');
        self::assertSame(1000, $details['amount'], 'The full amount is left alone');
    }

    #[Test]
    #[DataProvider('nothingRefundableProvider')]
    public function shouldThrowWhenThereIsNothingLeftToRefund(?int $balance, string $expectedMessage): void
    {
        $details = new ArrayObject(['quickpayPaymentId' => 1001, 'amount' => 1000]);

        /** @var Refund $refund */
        $refund = new static::$requestClass($details);

        $action = new RefundAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        $this->queuePayment([
            'state' => PaymentState::Processed->value,
            'balance' => $balance,
            'operations' => [$this->operation(OperationType::Refund, amount: 1000)],
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($expectedMessage);

        try {
            $action->execute($refund);
        } finally {
            // The fetch happened; the refund must not have been attempted.
            self::assertCount(1, $this->getRequests());
        }
    }

    /**
     * @return iterable<string, array{int|null, string}>
     */
    public static function nothingRefundableProvider(): iterable
    {
        yield 'fully refunded already' => [0, 'the balance is 0'];
        yield 'balance absent from the response' => [null, 'the balance is unknown'];
    }

    #[Test]
    public function shouldRefundThePartialAmountWhenTheDetailsCarryAnOverride(): void
    {
        $details = new ArrayObject([
            'quickpayPaymentId' => 1001,
            'amount' => 1000,
            'refund_amount' => 250,
        ]);

        /** @var Refund $refund */
        $refund = new static::$requestClass($details);

        $action = new RefundAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        $this->queuePayment([
            'state' => PaymentState::Processed->value,
            'balance' => 750,
            'operations' => [$this->operation(OperationType::Refund, amount: 250)],
        ]);

        $action->execute($refund);

        $requests = $this->getRequests();
        self::assertCount(1, $requests, 'An explicit amount must skip the balance fetch entirely');
        $this->assertRequest($requests[0], 'POST', '#/payments/1001/refund$#');
        self::assertSame(250, $this->decodeBody($requests[0])['amount'], 'The override must win over the full amount');

        self::assertFalse(
            $details->offsetExists('refund_amount'),
            'The override must be consumed, or the next refund would silently be partial too',
        );
        self::assertSame(1000, $details['amount'], 'The full amount must be left alone');
    }

    /**
     * A failed call did not carry out the instruction, so the override has to survive for a retry —
     * otherwise retrying a failed partial refund would refund the full amount.
     */
    #[Test]
    public function shouldKeepTheOverrideWhenTheRefundFails(): void
    {
        $details = new ArrayObject([
            'quickpayPaymentId' => 1001,
            'amount' => 1000,
            'refund_amount' => 250,
        ]);

        /** @var Refund $refund */
        $refund = new static::$requestClass($details);

        $action = new RefundAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        $this->queueResponse('{"message":"Validation error"}', 400);

        try {
            $action->execute($refund);
            self::fail('Expected the validation error to surface');
        } catch (ValidationException) {
            self::assertSame(250, $details['refund_amount']);
        }
    }

    /**
     * Synchronized, a declined refund comes back as a 2xx with the completed operation not approved.
     * The action reads that outcome and throws; the override survives, like for any failed call.
     */
    #[Test]
    public function shouldThrowWhenASynchronizedRefundIsDeclined(): void
    {
        $details = new ArrayObject(['quickpayPaymentId' => 1001, 'amount' => 1000, 'refund_amount' => 250]);

        /** @var Refund $refund */
        $refund = new static::$requestClass($details);

        $action = new RefundAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->createApi(synchronized: true));

        $this->queuePayment([
            'state' => PaymentState::Processed->value,
            'balance' => 1000,
            'operations' => [
                $this->operation(OperationType::Capture, amount: 1000),
                ['id' => 2, 'type' => 'refund', 'amount' => 250, 'pending' => false, 'qp_status_code' => '40000', 'qp_status_msg' => 'Rejected'],
            ],
        ]);

        try {
            $action->execute($refund);
            self::fail('Expected the decline to surface');
        } catch (OperationRejectedException $e) {
            self::assertSame('Quickpay declined the refund of payment 1001: status 40000 (Rejected).', $e->getMessage());
            self::assertSame(250, $details['refund_amount'], 'The instruction survives for a retry');
        }

        $requests = $this->getRequests();
        self::assertCount(1, $requests);
        self::assertSame('synchronized', $requests[0]->getUri()->getQuery());
    }

    /**
     * Asynchronously the returned payment carries the refund as pending — no outcome yet, not a
     * decline. The action completes and consumes the override.
     */
    #[Test]
    public function shouldNotMistakeAPendingRefundForADecline(): void
    {
        $details = new ArrayObject(['quickpayPaymentId' => 1001, 'amount' => 1000, 'refund_amount' => 250]);

        /** @var Refund $refund */
        $refund = new static::$requestClass($details);

        $action = new RefundAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        $this->queuePayment([
            'state' => PaymentState::Processed->value,
            'balance' => 1000,
            'operations' => [
                $this->operation(OperationType::Capture, amount: 1000),
                $this->operation(OperationType::Refund, null, amount: 250, pending: true),
            ],
        ]);

        $action->execute($refund);

        self::assertFalse($details->offsetExists('refund_amount'));
    }
}
