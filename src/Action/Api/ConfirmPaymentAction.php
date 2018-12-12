<?php

namespace Setono\Payum\QuickPay\Action\Api;

use Setono\Payum\QuickPay\Model\QuickPayPaymentOperation;
use Setono\Payum\QuickPay\Request\Api\ConfirmPayment;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\LogicException;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Model\Payment;
use Payum\Core\Reply\HttpResponse;

/**
 * @author jdk
 */
class ConfirmPaymentAction implements ActionInterface, GatewayAwareInterface, ApiAwareInterface
{
    use GatewayAwareTrait;
    use ApiAwareTrait;

    /**
     * {@inheritDoc}
     *
     * @param ConfirmPayment $request
     */
    public function execute($request)
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $model = ArrayObject::ensureArrayObject($request->getModel());
        if (false == $model['quickpayPaymentId']) {
            throw new LogicException('The payment has not been created');
        }

        $quickpayPayment = $this->api->getPayment($model, false);

        if ($quickpayPayment->getLatestOperation()->getType() == QuickPayPaymentOperation::TYPE_AUTHORIZE) {
            if ($this->api->getOption('auto_capture') == 1 && intval($quickpayPayment->getAuthorizedAmount()) == intval($model['amount'])) {
                $this->api->capturePayment($quickpayPayment, $model);
            } else {
                throw new LogicException(sprintf("Authorized amount does not match. Authorized %s expected %s", $quickpayPayment->getAuthorizedAmount(), $model['amount']));
            }
        }
        return 'OK';
    }
    /**
     * {@inheritDoc}
     */
    public function supports($request)
    {
        return
            $request instanceof ConfirmPayment &&
            $request->getModel() instanceof \ArrayAccess
            ;
    }
}
