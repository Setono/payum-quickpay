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
use Setono\Quickpay\Exception\QuickpayException;
use Setono\Quickpay\Exception\ResponseAwareException;

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
        } catch (QuickpayException $e) {
            // Quickpay rejects cancelling a payment that is already captured/cancelled with a
            // "Transaction in wrong state for this operation" error. Treat that as a no-op so a
            // cancel is idempotent; rethrow anything else.
            if ($e instanceof ResponseAwareException &&
                false !== stripos((string) $e->getMessageText(), 'Transaction in wrong state for this operation')) {
                return;
            }

            throw $e;
        }
    }

    public function supports($request): bool
    {
        return $request instanceof Cancel && $request->getModel() instanceof ArrayAccess;
    }
}
