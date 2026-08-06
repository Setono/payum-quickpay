<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Action;

use ArrayAccess;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\GetStatusInterface;
use Setono\Payum\Quickpay\Action\Api\ApiAwareTrait;
use Setono\Payum\Quickpay\Operations;
use Setono\Quickpay\Enum\OperationType;
use Setono\Quickpay\Enum\PaymentState;

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

        if (!$model->offsetExists('quickpayPaymentId')) {
            $request->markNew();

            return;
        }

        $payment = $this->api->payments()->getById((int) $model['quickpayPaymentId']);
        $operations = $payment->operations;

        switch ($payment->state()) {
            case PaymentState::Initial:
                $request->markNew();

                break;
            case PaymentState::New:
                if (Operations::isLatestApproved($operations, OperationType::Authorize)) {
                    $request->markAuthorized();
                } else {
                    $request->markFailed();
                }

                break;
            case PaymentState::Pending:
                $request->markPending();

                break;
            case PaymentState::Rejected:
            case PaymentState::Invalid:
                $request->markFailed();

                break;
            case PaymentState::Processed:
                $latestOperation = Operations::latest($operations);
                if (Operations::isApprovedOfType($latestOperation, OperationType::Capture)) {
                    $request->markCaptured();
                } elseif (Operations::isApprovedOfType($latestOperation, OperationType::Refund)) {
                    // A refund does not necessarily empty the payment. Quickpay's `balance` is what is
                    // still captured (captured minus refunded), so a partial refund leaves it positive
                    // and the money is, in Payum's vocabulary, still captured — there is no partial
                    // mark to reach for. Only a balance of zero is genuinely refunded.
                    //
                    // `balance` is nullable in the API; when it is absent, fall back to treating any
                    // refund as full rather than inventing a number.
                    if (null === $payment->balance || 0 === $payment->balance) {
                        $request->markRefunded();
                    } else {
                        $request->markCaptured();
                    }
                } elseif (Operations::isApprovedOfType($latestOperation, OperationType::Cancel)) {
                    $request->markCanceled();
                } else {
                    $request->markUnknown();
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
}
