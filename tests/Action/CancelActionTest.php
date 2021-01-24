<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Tests\Action;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Model\Token;
use Payum\Core\Request\Cancel;
use Payum\Core\Request\Convert;
use Setono\Payum\QuickPay\Action\CancelAction;
use Setono\Payum\QuickPay\Action\ConvertPaymentAction;
use Setono\Payum\QuickPay\Model\QuickPayPaymentOperation;

class CancelActionTest extends ActionTestAbstract
{
    protected $requestClass = Cancel::class;

    protected $actionClass = CancelAction::class;

    /**
     * @test
     */
    public function shouldCancelPayment(): void
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
        self::assertEquals(QuickPayPaymentOperation::TYPE_AUTHORIZE, $quickpayPayment->getLatestOperation()->getType());

        /** @var Cancel $cancel */
        $cancel = new $this->requestClass($token);
        $cancel->setModel($details);

        /** @var CancelAction $action */
        $action = new $this->actionClass();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        $action->execute($cancel);

        $quickpayPayment = $this->api->getPayment(new ArrayObject(['quickpayPaymentId' => $details['quickpayPayment']->getId()]));
        self::assertEquals(QuickPayPaymentOperation::TYPE_CANCEL, $quickpayPayment->getLatestOperation()->getType());
    }
}
