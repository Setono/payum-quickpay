<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Action\Api;

use ArrayAccess;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\LogicException;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Setono\Payum\Quickpay\Operations;
use Setono\Payum\Quickpay\Request\Api\ConfirmPayment;
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

        // Persist the balance before any early return below, so a callback for a payment with nothing
        // to confirm still refreshes it.
        $model['balance'] = $payment->balance;

        $latestOperation = Operations::latest($payment->operations);
        if (null === $latestOperation) {
            // A payment can legitimately have no operations yet — Quickpay fires a callback when the
            // payment is merely created, which becomes visible as soon as an account-wide callback url
            // (Settings → Integration) is configured. There is nothing to confirm, so do nothing.
            // Throwing here would 500 the notify endpoint, and Quickpay would retry a callback that
            // can never succeed.
            return;
        }

        // Only an APPROVED authorize is worth capturing. The callback also fires for a rejected
        // authorize (a declined card is routine in the payment window) and for one still pending —
        // both have type `authorize`, so gating on the type alone would fall through to the amount
        // check below, find an authorized amount of 0 and throw. Throwing 500s the notify endpoint
        // and has Quickpay retry a callback that can never succeed; a not-approved authorize is
        // simply nothing to confirm.
        if ($this->api->isAutoCapture() && Operations::isApprovedOfType($latestOperation, OperationType::Authorize)) {
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
            );
        }
    }

    public function supports($request): bool
    {
        return $request instanceof ConfirmPayment && $request->getModel() instanceof ArrayAccess;
    }
}
