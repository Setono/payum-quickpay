<?php

declare(strict_types=1);

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

class ConfirmPaymentAction implements ActionInterface, GatewayAwareInterface, ApiAwareInterface
{
    use GatewayAwareTrait;
    use ApiAwareTrait;

    /**
     * {@inheritdoc}
     *
     * @param ConfirmPayment $request
     */
    public function execute($request)
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $model = ArrayObject::ensureArrayObject($request->getModel());
        if (false === $model['quickpayPaymentId']) {
            throw new LogicException('The payment has not been created');
        }

        $quickpayPayment = $this->api->getPayment($model, false);

        $latestOperation = $quickpayPayment->getLatestOperation();

        if (null === $latestOperation) {
            throw new LogicException('The payment does not have a `latest operation`');
        }

        if (1 === (int) $this->api->getOption('auto_capture') && QuickPayPaymentOperation::TYPE_AUTHORIZE === $latestOperation->getType()) {
            if ($quickpayPayment->getAuthorizedAmount() === (int) $model['amount']) {
                $this->api->capturePayment($quickpayPayment, $model);
            } else {
                throw new LogicException(sprintf('Authorized amount does not match. Authorized %s expected %s', $quickpayPayment->getAuthorizedAmount(), $model['amount']));
            }
        }

        return 'OK';
    }

    /**
     * {@inheritdoc}
     */
    public function supports($request)
    {
        return
            $request instanceof ConfirmPayment &&
            $request->getModel() instanceof \ArrayAccess
            ;
    }
}
