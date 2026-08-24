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
use Setono\Payum\Quickpay\Details;
use Setono\Quickpay\Enum\OperationType;
use Setono\Quickpay\Enum\PaymentState;
use Setono\Quickpay\Response\Payment\Payment;

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

        if (!Details::hasPaymentId($model)) {
            $request->markNew();

            return;
        }

        $payment = $this->api->payments()->getById(Details::paymentId($model));

        // The payment is already in hand, so persisting the balance costs nothing — and it is the
        // number a consumer needs for refund handling, which the Payum marks cannot express.
        $model['balance'] = $payment->balance;

        switch ($payment->state()) {
            case PaymentState::Initial:
                $request->markNew();

                break;
            case PaymentState::New:
                // Decided by the last APPROVED operation, not the last one recorded: a trailing
                // rejected or pending attempt (say, a capture that failed) must not make an
                // authorized payment — whose money is still held — report as failed.
                if (true === $payment->latestApprovedOperation()?->isOfType(OperationType::Authorize)) {
                    $request->markAuthorized();
                } else {
                    $request->markFailed();
                }

                break;
            case PaymentState::Pending:
                // `pending` is Quickpay's state whenever an operation is in flight — an authorize held
                // up in 3-D Secure, but ALSO an asynchronous capture, refund or cancel that has not
                // settled (verified live: a captured payment reads `pending` for the ~second its
                // refund takes). An operation in flight never changes what already happened to the
                // money, so when something has been approved, that decides — a captured payment with a
                // refund in flight is still captured, an authorized one with a capture queued still
                // authorized. Only a payment with no approved operation yet is genuinely pending.
                if (!self::markFromLastApprovedOperation($request, $payment)) {
                    $request->markPending();
                }

                break;
            case PaymentState::Rejected:
            case PaymentState::Invalid:
                $request->markFailed();

                break;
            case PaymentState::Processed:
                // Same principle as above: the list may end in a rejected or still-pending attempt
                // (a refund the acquirer bounced, an async operation not yet settled), and deciding
                // from that trailing attempt would flip a payment whose money is demonstrably still
                // held to `unknown`. The last approved operation is what actually happened.
                if (!self::markFromLastApprovedOperation($request, $payment)) {
                    $request->markUnknown();
                }

                break;
            default:
                $request->markUnknown();
        }
    }

    /**
     * Marks the request from the payment's last APPROVED operation — what actually happened to the
     * money, whatever attempts came after it. Returns false when nothing has been approved, leaving
     * the caller to say what that means in the payment's state.
     */
    private static function markFromLastApprovedOperation(GetStatusInterface $request, Payment $payment): bool
    {
        $latestApproved = $payment->latestApprovedOperation();

        if (null === $latestApproved) {
            return false;
        }

        if ($latestApproved->isOfType(OperationType::Authorize)) {
            $request->markAuthorized();
        } elseif ($latestApproved->isOfType(OperationType::Capture)) {
            $request->markCaptured();
        } elseif ($latestApproved->isOfType(OperationType::Refund)) {
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
        } elseif ($latestApproved->isOfType(OperationType::Cancel)) {
            $request->markCanceled();
        } else {
            // An approved operation of a type the gateway does not map (recurring, subscribe, test).
            return false;
        }

        return true;
    }

    public function supports($request): bool
    {
        return $request instanceof GetStatusInterface && $request->getModel() instanceof ArrayAccess;
    }
}
