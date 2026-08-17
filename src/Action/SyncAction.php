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
use Payum\Core\Request\Sync;
use Setono\Payum\Quickpay\Action\Api\ApiAwareTrait;
use Setono\Payum\Quickpay\Details;

/**
 * Refreshes the scalar snapshot in the details from Quickpay.
 *
 * `Sync` is Payum's standard "bring the details up to date with the gateway" request. Use it when you
 * want current state without asking a question about it — `GetStatus` answers "what is this payment's
 * status", this answers "what does Quickpay currently say".
 *
 * Only scalars are written, per the 2.0 details contract: `balance` (what is still captured, i.e.
 * captured minus refunded — the number Payum's marks cannot express) and `state`.
 */
class SyncAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use GatewayAwareTrait;
    use ApiAwareTrait;

    /**
     * @param mixed|Sync $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $model = ArrayObject::ensureArrayObject($request->getModel());

        // Nothing to sync before the payment exists at Quickpay. That is a normal state for a model
        // that has not been converted yet, not an error, so this is a no-op rather than a throw.
        if (!Details::hasPaymentId($model)) {
            return;
        }

        $payment = $this->api->payments()->getById(Details::paymentId($model));

        $model['balance'] = $payment->balance;
        $model['state'] = $payment->state;
    }

    public function supports($request): bool
    {
        return $request instanceof Sync && $request->getModel() instanceof ArrayAccess;
    }
}
