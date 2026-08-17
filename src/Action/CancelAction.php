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
use Setono\Payum\Quickpay\Details;
use Setono\Payum\Quickpay\Exception\OperationPendingException;
use Setono\Payum\Quickpay\Exception\OperationRejectedException;
use Setono\Quickpay\Enum\OperationType;

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

        $paymentId = Details::paymentId($model);
        $payment = $this->api->payments()->getById($paymentId);

        // Keep it fresh for the caller while we have it — same key every fetching action writes.
        $model['balance'] = $payment->balance;

        // One money operation at a time: a capture, refund or cancel still in flight has not settled,
        // and a cancel on top would race it.
        OperationPendingException::assertNoneInFlight($paymentId, $payment);

        // Errors are deliberately not caught here. Quickpay rejects cancelling an already captured or
        // cancelled payment with a `ValidationException`, and that surfaces to the caller: it is a real
        // state conflict, and swallowing it would tell a shop it had cancelled a payment whose money is
        // still held. A caller that genuinely wants a no-op can catch the typed exception itself.
        // The callback is routed to the payment's own notify url — see Details::callbackUrl().
        $cancelled = $this->api->payments()->cancel($paymentId, callbackUrl: Details::callbackUrl($model));

        // Asynchronously the returned payment is a snapshot with the cancel still pending and nothing
        // to read; synchronized, it carries the outcome — and a decline is a 2xx.
        OperationRejectedException::assertNotRejected($paymentId, $cancelled, OperationType::Cancel);
    }

    public function supports($request): bool
    {
        return $request instanceof Cancel && $request->getModel() instanceof ArrayAccess;
    }
}
