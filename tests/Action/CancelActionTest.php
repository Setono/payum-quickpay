<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Tests\Action;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Request\Cancel;
use Setono\Payum\Quickpay\Action\CancelAction;
use Setono\Quickpay\Enum\OperationType;
use Setono\Quickpay\Enum\PaymentState;
use Setono\Quickpay\Exception\ValidationException;

class CancelActionTest extends ActionTestAbstract
{
    protected $requestClass = Cancel::class;

    protected $actionClass = CancelAction::class;

    /**
     * @test
     */
    public function shouldCancelPayment(): void
    {
        $details = new ArrayObject(['quickpayPaymentId' => 1001, 'amount' => 100]);

        /** @var Cancel $cancel */
        $cancel = new $this->requestClass($details);

        $action = new CancelAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        $this->queuePayment([
            'state' => PaymentState::Processed->value,
            'operations' => [$this->operation(OperationType::Cancel)],
        ]);

        $action->execute($cancel);

        $requests = $this->getRequests();
        self::assertCount(1, $requests);
        $this->assertRequest($requests[0], 'POST', '#/payments/1001/cancel$#');
        // Cancel takes no body.
        self::assertSame('', (string) $requests[0]->getBody());
    }

    /**
     * @test
     */
    public function shouldSwallowWrongStateError(): void
    {
        $details = new ArrayObject(['quickpayPaymentId' => 1001, 'amount' => 100]);

        /** @var Cancel $cancel */
        $cancel = new $this->requestClass($details);

        $action = new CancelAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        // Quickpay reports an already finalized payment as a wrong-state error; the action treats it
        // as a no-op so cancelling is idempotent.
        $this->queueResponse('{"message":"Transaction in wrong state for this operation"}', 400);

        $action->execute($cancel);

        self::assertCount(1, $this->getRequests());
    }

    /**
     * @test
     */
    public function shouldRethrowOtherErrors(): void
    {
        $details = new ArrayObject(['quickpayPaymentId' => 1001, 'amount' => 100]);

        /** @var Cancel $cancel */
        $cancel = new $this->requestClass($details);

        $action = new CancelAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        $this->queueResponse('{"message":"Some other error"}', 400);

        $this->expectException(ValidationException::class);
        $action->execute($cancel);
    }
}
