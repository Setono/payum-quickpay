<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Tests\Action;

use Payum\Core\Bridge\Spl\ArrayObject;
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
     * @test
     */
    public function shouldRefundPayment(): void
    {
        $details = new ArrayObject(['quickpayPaymentId' => 1001, 'amount' => 100]);

        /** @var Refund $refund */
        $refund = new $this->requestClass($details);

        $action = new RefundAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        $this->queuePayment([
            'state' => PaymentState::Processed->value,
            'operations' => [$this->operation(OperationType::Refund)],
        ]);

        $action->execute($refund);

        $requests = $this->getRequests();
        self::assertCount(1, $requests);
        $this->assertRequest($requests[0], 'POST', '#/payments/1001/refund$#');
        self::assertSame(100, $this->decodeBody($requests[0])['amount']);
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
        self::assertCount(1, $requests);
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
