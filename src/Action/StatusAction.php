<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Action;

use ArrayAccess;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\GetStatusInterface;
use Setono\Payum\QuickPay\Action\Api\ApiAwareTrait;
use Setono\Payum\QuickPay\Model\QuickPayPayment;
use Setono\Payum\QuickPay\Model\QuickPayPaymentOperation;

class StatusAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use GatewayAwareTrait;

    use ApiAwareTrait;

    /**
     * @param mixed|GetStatusInterface $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $model = ArrayObject::ensureArrayObject($request->getModel());

        if (!$model->offsetExists('quickpayPaymentId') && !$model->offsetExists('quickpayPayment')) {
            $request->markNew();

            return;
        }

        $quickpayPayment = $this->api->getPayment($model);
        $latestOperation = $quickpayPayment->getLatestOperation();

        switch ($quickpayPayment->getState()) {
            case QuickPayPayment::STATE_INITIAL:
                $request->markNew();

                break;
            case QuickPayPayment::STATE_NEW:
                if ($this->isOperationApproved($latestOperation, QuickPayPaymentOperation::TYPE_AUTHORIZE)) {
                    $request->markAuthorized();
                } else {
                    $request->markFailed();
                }

                break;
            case QuickPayPayment::STATE_PENDING:
                $request->markPending();

                break;
            case QuickPayPayment::STATE_REJECTED:
                $request->markFailed();

                break;
            case QuickPayPayment::STATE_PROCESSED:
                if ($this->isOperationApproved($latestOperation, QuickPayPaymentOperation::TYPE_CAPTURE)) {
                    $request->markCaptured();
                } elseif ($this->isOperationApproved($latestOperation, QuickPayPaymentOperation::TYPE_REFUND)) {
                    $request->markRefunded();
                } elseif ($this->isOperationApproved($latestOperation, QuickPayPaymentOperation::TYPE_CANCEL)) {
                    $request->markCanceled();
                }

                break;
            default:
                $request->markUnknown();
        }
    }

    public function supports($request): bool
    {
        return $request instanceof GetStatusInterface && $request->getModel() instanceof ArrayAccess;
    }

    private function isOperationApproved(?QuickPayPaymentOperation $operation, string $state): bool
    {
        if (null === $operation) {
            return false;
        }

        return $operation->getType() === $state && $operation->isApproved();
    }
}
