<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Tests\Action;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\LogicException;
use Payum\Core\Request\Notify;
use Setono\Payum\QuickPay\Action\NotifyAction;
use Setono\Payum\QuickPay\Model\QuickPayPayment;
use Setono\Payum\QuickPay\Model\QuickPayPaymentOperation;

class NotifyActionTest extends ActionTestAbstract
{
    protected $requestClass = Notify::class;

    protected $actionClass = NotifyAction::class;

    /**
     * @test
     *
     * @throws \Exception
     */
    public function shouldHandleNotify(): void
    {
        $params = new ArrayObject([
            'payment' => $this->createPayment(),
        ]);
        $quickpayPayment = $this->api->getPayment($params);

        $this->api->authorizePayment($quickpayPayment, new ArrayObject([
            'card' => $this->getTestCard()->toArray(),
            'acquirer' => 'clearhaus',
            'amount' => $params['payment']->getTotalAmount(),
        ]));

        /** @var Notify $notify */
        $notify = new $this->requestClass([]);
        $notify->setModel(new ArrayObject([]));

        /** @var NotifyAction $action */
        $action = new $this->actionClass();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        try {
            $action->execute($notify);
        } catch (LogicException $le) {
            $this->assertEquals('The payment has not been created', $le->getMessage());
        }

        // Use incorrect amount to trigger error
        $notify->setModel(new ArrayObject([
            'quickpayPaymentId' => $quickpayPayment->getId(),
            'amount' => $params['payment']->getTotalAmount() - 1,
        ]));

        try {
            $action->execute($notify);
        } catch (LogicException $le) {
            $this->assertStringStartsWith('Authorized amount does not match', $le->getMessage());
        }

        $notify->setModel(new ArrayObject([
            'quickpayPaymentId' => $quickpayPayment->getId(),
            'amount' => $params['payment']->getTotalAmount(),
        ]));

        $action->execute($notify);

        $quickpayPayment = $this->api->getPayment(new ArrayObject([
            'quickpayPaymentId' => $quickpayPayment->getId(),
        ]));

        $this->assertEquals(QuickpayPayment::STATE_PROCESSED, $quickpayPayment->getState());
        $this->assertEquals(QuickPayPaymentOperation::TYPE_CAPTURE, $quickpayPayment->getLatestOperation()->getType());
        $this->assertEquals(QuickPayPaymentOperation::STATUS_CODE_APPROVED, $quickpayPayment->getLatestOperation()->getStatusCode());
    }
}
