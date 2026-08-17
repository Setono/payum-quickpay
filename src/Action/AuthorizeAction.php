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
use Payum\Core\Request\Authorize;
use Setono\Payum\Quickpay\Action\Api\ApiAwareTrait;
use Setono\Payum\Quickpay\Details;
use Setono\Payum\Quickpay\Request\Api\CreatePaymentLink;
use Setono\Quickpay\Enum\OperationType;

/**
 * Authorize: send the customer through the hosted payment window to have the amount reserved on
 * their card, without capturing it. The money is captured later with {@see \Payum\Core\Request\Capture}
 * — or never, with {@see \Payum\Core\Request\Cancel}.
 *
 * The action runs twice in an interactive flow: once to create the link and redirect, and once more
 * when Quickpay sends the customer back to the token url (`continue_url`). The second run must be a
 * no-op — the authorization has happened, redirecting again would loop — so the decision is made
 * from the payment's operations, not from "was I called before".
 */
class AuthorizeAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use GatewayAwareTrait;
    use ApiAwareTrait;

    /**
     * @param mixed|Authorize $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $model = ArrayObject::ensureArrayObject($request->getModel());

        $payment = $this->api->payments()->getById(Details::paymentId($model));

        // The payment is in hand, so keep the balance fresh — same key every fetching action writes.
        $model['balance'] = $payment->balance;

        // Already authorized (the return trip, or a repeat call), or already past authorization —
        // a capture means the money was authorized and taken. Nothing left for Authorize to do.
        if ($payment->hasApprovedOperation(OperationType::Authorize) ||
            $payment->hasApprovedOperation(OperationType::Capture) ||
            $payment->hasPendingOperation(OperationType::Capture)) {
            return;
        }

        // An authorize is in flight (3-D Secure, an asynchronous acquirer). Creating a fresh link now
        // would race its outcome; the next call — after the callback, or the customer's return —
        // will see the result.
        if ($payment->hasPendingOperation(OperationType::Authorize)) {
            return;
        }

        // Nothing approved and nothing pending: a fresh payment, or one whose previous attempt was
        // declined (Quickpay lets the customer try again on the same payment). Send them to the
        // window. Whether the window also captures is the deprecated `auto_capture` option — the
        // request that says "capture" is Capture, and it drives the flow the same way.
        $this->gateway->execute(new CreatePaymentLink(
            $model,
            autoCapture: $this->api->isAutoCapture(),
            token: $request->getToken(),
        ));
    }

    public function supports($request): bool
    {
        return $request instanceof Authorize && $request->getModel() instanceof ArrayAccess;
    }
}
