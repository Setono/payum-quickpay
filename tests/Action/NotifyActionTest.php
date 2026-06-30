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
     */
    public function shouldHandleNotify(): void
    {
        $payment = $this->createPayment();

        $this->queuePayment(['id' => 1001, 'state' => QuickPayPayment::STATE_INITIAL]);
        $quickpayPayment = $this->api->getPayment(new ArrayObject(['payment' => $payment]));

        $this->queuePayment([
            'id' => 1001,
            'state' => QuickPayPayment::STATE_NEW,
            'operations' => [$this->operation(QuickPayPaymentOperation::TYPE_AUTHORIZE, QuickPayPaymentOperation::STATUS_CODE_APPROVED, 100)],
        ]);
        $this->api->authorizePayment($quickpayPayment, new ArrayObject([
            'card' => $this->getTestCard()->toArray(),
            'acquirer' => 'clearhaus',
            'amount' => $payment->getTotalAmount(),
        ]));

        /** @var Notify $notify */
        $notify = new $this->requestClass([]);
        $notify->setModel(new ArrayObject([]));

        /** @var NotifyAction $action */
        $action = new $this->actionClass();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        // No payment id in the model -> ConfirmPayment fails (no HTTP call).
        try {
            $action->execute($notify);
        } catch (LogicException $le) {
            self::assertEquals('The payment has not been created', $le->getMessage());
        }

        // Wrong amount -> the authorized amount does not match (ConfirmPayment reloads the payment).
        $this->queuePayment([
            'id' => 1001,
            'state' => QuickPayPayment::STATE_NEW,
            'operations' => [$this->operation(QuickPayPaymentOperation::TYPE_AUTHORIZE, QuickPayPaymentOperation::STATUS_CODE_APPROVED, 100)],
        ]);
        $notify->setModel(new ArrayObject([
            'quickpayPaymentId' => $quickpayPayment->getId(),
            'amount' => $payment->getTotalAmount() - 1,
        ]));

        try {
            $action->execute($notify);
        } catch (LogicException $le) {
            self::assertStringStartsWith('Authorized amount does not match', $le->getMessage());
        }

        // Correct amount -> the payment is captured (reload, then capture).
        $this->queuePayment([
            'id' => 1001,
            'state' => QuickPayPayment::STATE_NEW,
            'operations' => [$this->operation(QuickPayPaymentOperation::TYPE_AUTHORIZE, QuickPayPaymentOperation::STATUS_CODE_APPROVED, 100)],
        ]);
        $this->queuePayment([
            'id' => 1001,
            'state' => QuickPayPayment::STATE_PROCESSED,
            'operations' => [$this->operation(QuickPayPaymentOperation::TYPE_CAPTURE)],
        ]);
        $notify->setModel(new ArrayObject([
            'quickpayPaymentId' => $quickpayPayment->getId(),
            'amount' => $payment->getTotalAmount(),
        ]));

        $action->execute($notify);

        $this->queuePayment([
            'id' => 1001,
            'state' => QuickPayPayment::STATE_PROCESSED,
            'operations' => [$this->operation(QuickPayPaymentOperation::TYPE_CAPTURE)],
        ]);
        $quickpayPayment = $this->api->getPayment(new ArrayObject([
            'quickpayPaymentId' => $quickpayPayment->getId(),
        ]));

        self::assertEquals(QuickPayPayment::STATE_PROCESSED, $quickpayPayment->getState());
        self::assertEquals(QuickPayPaymentOperation::TYPE_CAPTURE, $quickpayPayment->getLatestOperation()->getType());
        self::assertEquals(QuickPayPaymentOperation::STATUS_CODE_APPROVED, $quickpayPayment->getLatestOperation()->getStatusCode());
    }
}
