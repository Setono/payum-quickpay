<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Tests\Action;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Model\Token;
use Payum\Core\Request\Convert;
use Payum\Core\Request\Refund;
use Setono\Payum\QuickPay\Action\ConvertPaymentAction;
use Setono\Payum\QuickPay\Action\RefundAction;
use Setono\Payum\QuickPay\Model\QuickPayPayment;
use Setono\Payum\QuickPay\Model\QuickPayPaymentOperation;

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
        $payment = $this->createPayment();

        $token = new Token();
        $token->setTargetUrl('theCallbackUrl');
        $token->setAfterUrl('theContinueUrl');
        $token->setGatewayName('quickpay');

        $convert = new Convert($payment, 'array', $token);

        $this->queuePayment(['id' => 1001, 'state' => QuickPayPayment::STATE_INITIAL]);

        $convertPaymentAction = new ConvertPaymentAction();
        $convertPaymentAction->setGateway($this->gateway);
        $convertPaymentAction->setApi($this->api);
        $convertPaymentAction->execute($convert);

        $payment->setDetails($convert->getResult());
        $details = ArrayObject::ensureArrayObject($payment->getDetails());
        $token->setDetails($details);

        // Authorize payment with test card.
        $details['card'] = $this->getTestCard()->toArray();
        $details['acquirer'] = 'clearhaus';
        $this->queuePayment([
            'id' => 1001,
            'state' => QuickPayPayment::STATE_NEW,
            'operations' => [$this->operation(QuickPayPaymentOperation::TYPE_AUTHORIZE)],
        ]);
        $quickpayPayment = $this->api->authorizePayment($details['quickpayPayment'], $details);
        self::assertEquals(QuickPayPaymentOperation::TYPE_AUTHORIZE, $quickpayPayment->getLatestOperation()->getType());

        // Capture payment.
        $this->queuePayment([
            'id' => 1001,
            'state' => QuickPayPayment::STATE_PROCESSED,
            'operations' => [$this->operation(QuickPayPaymentOperation::TYPE_CAPTURE)],
        ]);
        $quickpayPayment = $this->api->capturePayment($details['quickpayPayment'], $details);
        self::assertEquals(QuickPayPaymentOperation::TYPE_CAPTURE, $quickpayPayment->getLatestOperation()->getType());

        /** @var Refund $refund */
        $refund = new $this->requestClass($token);
        $refund->setModel($details);

        /** @var RefundAction $action */
        $action = new $this->actionClass();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        // The refund operation itself.
        $this->queuePayment([
            'id' => 1001,
            'state' => QuickPayPayment::STATE_PROCESSED,
            'operations' => [$this->operation(QuickPayPaymentOperation::TYPE_REFUND)],
        ]);
        $action->execute($refund);

        // Reload to assert the refund operation.
        $this->queuePayment([
            'id' => 1001,
            'state' => QuickPayPayment::STATE_PROCESSED,
            'operations' => [$this->operation(QuickPayPaymentOperation::TYPE_REFUND)],
        ]);
        $quickpayPayment = $this->api->getPayment(new ArrayObject(['quickpayPaymentId' => $details['quickpayPayment']->getId()]));
        self::assertEquals(QuickPayPaymentOperation::TYPE_REFUND, $quickpayPayment->getLatestOperation()->getType());
    }
}
