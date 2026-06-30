<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Tests\Action;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Request\Refund;
use Setono\Payum\QuickPay\Action\RefundAction;
use Setono\Quickpay\Enum\OperationType;
use Setono\Quickpay\Enum\PaymentState;

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
}
