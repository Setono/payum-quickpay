<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Action\Api;

use ArrayAccess;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\LogicException;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Setono\Payum\QuickPay\Operations;
use Setono\Payum\QuickPay\Request\Api\ConfirmPayment;
use Setono\Quickpay\Enum\OperationType;
use Setono\Quickpay\Request\Payment\CaptureRequest;

class ConfirmPaymentAction implements ActionInterface, GatewayAwareInterface, ApiAwareInterface
{
    use GatewayAwareTrait;
    use ApiAwareTrait;

    /**
     * @param mixed|ConfirmPayment $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $model = ArrayObject::ensureArrayObject($request->getModel());
        if (!$model->offsetExists('quickpayPaymentId')) {
            throw new LogicException('The payment has not been created');
        }

        $payment = $this->api->payments()->getById((int) $model['quickpayPaymentId']);

        $latestOperation = Operations::latest($payment->operations);
        if (null === $latestOperation) {
            throw new LogicException('The payment does not have a `latest operation`');
        }

        if ($this->api->isAutoCapture() && OperationType::Authorize === $latestOperation->type()) {
            $authorizedAmount = Operations::authorizedAmount($payment->operations);
            $expectedAmount = (int) $model['amount'];

            if ($authorizedAmount !== $expectedAmount) {
                throw new LogicException(sprintf(
                    'Authorized amount does not match. Authorized %s expected %s',
                    $authorizedAmount,
                    $expectedAmount,
                ));
            }

            $this->api->payments()->capture(
                (int) $model['quickpayPaymentId'],
                new CaptureRequest(amount: $expectedAmount),
                synchronized: $this->api->isSynchronized(),
            );
        }
    }

    public function supports($request): bool
    {
        return $request instanceof ConfirmPayment && $request->getModel() instanceof ArrayAccess;
    }
}
