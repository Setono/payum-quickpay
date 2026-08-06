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
use Payum\Core\Request\Cancel;
use Setono\Payum\Quickpay\Action\Api\ApiAwareTrait;
use Setono\Quickpay\Exception\ValidationException;

class CancelAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use GatewayAwareTrait;
    use ApiAwareTrait;

    /**
     * @param mixed|Cancel $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $model = ArrayObject::ensureArrayObject($request->getModel());

        try {
            $this->api->payments()->cancel((int) $model['quickpayPaymentId']);
        } catch (ValidationException $e) {
            // Quickpay rejects cancelling a payment that is already captured or cancelled. Treat only
            // that case as a no-op, so cancelling is idempotent; any other validation error is a real
            // failure and must surface.
            //
            // It has to be matched on the message: the response carries no error code for it
            // (`error_code` is null and `errors` is empty). The wording is not stable — v10 has
            // returned both "Transaction in wrong state for this operation" and (observed live,
            // 2026-08) "Validation error: Payment is not in a valid state for cancel" — so match the
            // stable part loosely rather than pinning an exact string.
            if (1 !== preg_match('/not (?:in )?a valid state|wrong state/i', (string) $e->getMessageText())) {
                throw $e;
            }
        }
    }

    public function supports($request): bool
    {
        return $request instanceof Cancel && $request->getModel() instanceof ArrayAccess;
    }
}
