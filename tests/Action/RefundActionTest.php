<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Tests\Action;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Model\Token;
use Payum\Core\Request\Authorize;
use Payum\Core\Request\Capture;
use Payum\Core\Request\Convert;
use Payum\Core\Request\Refund;
use Setono\Payum\QuickPay\Action\ConvertPaymentAction;
use Setono\Payum\QuickPay\Action\RefundAction;
use Setono\Payum\QuickPay\Model\QuickPayPaymentOperation;

class RefundActionTest extends ActionTestAbstract
{
    /**
     * @var string
     */
    protected $requestClass = Refund::class;

    /**
     * @var string
     */
    protected $actionClass = RefundAction::class;

    /**
     * @test
     *
     * @throws \Exception
     */
    public function shouldRefundPayment(): void
    {
        $payment = $this->createPayment();

        $token = new Token();
        $token->setTargetUrl('theCallbackUrl');
        $token->setAfterUrl('theContinueUrl');
        $token->setGatewayName('quickpay');

        $convert = new Convert($payment, 'array', $token);

        $convertPaymentAction = new ConvertPaymentAction();
        $convertPaymentAction->setGateway($this->gateway);
        $convertPaymentAction->setApi($this->api);
        $convertPaymentAction->execute($convert);

        $payment->setDetails($convert->getResult());
        $details = ArrayObject::ensureArrayObject($payment->getDetails());
        $token->setDetails($details);

        // Authorize payment with test card
        $details['card'] = $this->getTestCard()->toArray();
        $details['acquirer'] = 'clearhaus';
        $quickpayPayment = $this->api->authorizePayment($details['quickpayPayment'], $details);
        $this->assertEquals(QuickPayPaymentOperation::TYPE_AUTHORIZE, $quickpayPayment->getLatestOperation()->getType());

        // Capture payment
        $quickpayPayment = $this->api->capturePayment($details['quickpayPayment'], $details);
        $this->assertEquals(QuickPayPaymentOperation::TYPE_CAPTURE, $quickpayPayment->getLatestOperation()->getType());

        /** @var Refund $refund */
        $refund = new $this->requestClass($token);
        $refund->setModel($details);

        /** @var RefundAction $action */
        $action = new $this->actionClass();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        $action->execute($refund);

        $quickpayPayment = $this->api->getPayment(new ArrayObject(['quickpayPaymentId' => $details['quickpayPayment']->getId()]));
        $this->assertEquals(QuickPayPaymentOperation::TYPE_REFUND, $quickpayPayment->getLatestOperation()->getType());
    }
}
