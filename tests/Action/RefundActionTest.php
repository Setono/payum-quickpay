<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Tests\Action;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\LogicException;
use Payum\Core\Request\Refund;
use Setono\Payum\Quickpay\Action\RefundAction;
use Setono\Quickpay\Enum\OperationType;
use Setono\Quickpay\Enum\PaymentState;
use Setono\Quickpay\Exception\ValidationException;

class RefundActionTest extends ActionTestAbstract
{
    /** @var string */
    protected $requestClass = Refund::class;

    /** @var string */
    protected $actionClass = RefundAction::class;

    /**
     * A model without a quickpayPaymentId has no payment to refund. The guard throws before any
     * HTTP happens — without it, `(int) null = 0` would reach the API as a request on payment 0.
     *
     * @test
     */
    public function shouldThrowWhenThePaymentHasNotBeenCreated(): void
    {
        /** @var Refund $refund */
        $refund = new $this->requestClass(new ArrayObject(['amount' => 100]));

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
     *
     * @test
     */
    public function shouldRefundTheRemainingBalanceByDefault(): void
    {
        $details = new ArrayObject(['quickpayPaymentId' => 1001, 'amount' => 1000]);

        /** @var Refund $refund */
        $refund = new $this->requestClass($details);

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

    /**
     * @test
     *
     * @dataProvider nothingRefundableProvider
     */
    public function shouldThrowWhenThereIsNothingLeftToRefund(?int $balance, string $expectedMessage): void
    {
        $details = new ArrayObject(['quickpayPaymentId' => 1001, 'amount' => 1000]);

        /** @var Refund $refund */
        $refund = new $this->requestClass($details);

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

    /**
     * @test
     */
    public function shouldRefundThePartialAmountWhenTheDetailsCarryAnOverride(): void
    {
        $details = new ArrayObject([
            'quickpayPaymentId' => 1001,
            'amount' => 1000,
            'refund_amount' => 250,
        ]);

        /** @var Refund $refund */
        $refund = new $this->requestClass($details);

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
     *
     * @test
     */
    public function shouldKeepTheOverrideWhenTheRefundFails(): void
    {
        $details = new ArrayObject([
            'quickpayPaymentId' => 1001,
            'amount' => 1000,
            'refund_amount' => 250,
        ]);

        /** @var Refund $refund */
        $refund = new $this->requestClass($details);

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
}
