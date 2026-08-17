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
use Payum\Core\Request\Capture;
use Setono\Payum\Quickpay\Action\Api\ApiAwareTrait;
use Setono\Payum\Quickpay\Amounts;
use Setono\Payum\Quickpay\Details;
use Setono\Payum\Quickpay\Operations;
use Setono\Payum\Quickpay\Request\Api\CreatePaymentLink;
use Setono\Quickpay\Enum\OperationType;
use Setono\Quickpay\Request\Payment\CaptureRequest;
use Setono\Quickpay\Response\Payment\Payment;

/**
 * Capture: take the money. What that means depends on where the payment is:
 *
 * - **Not authorized yet** — the customer has to pay first, so this is the interactive entry point:
 *   the customer is sent through the hosted payment window with `auto_capture` on the link, and
 *   Quickpay captures the moment the card is authorized. This is Payum's convention (executing
 *   `Capture` against a fresh payment drives the whole flow) and what Payum's capture controller and
 *   Sylius's default checkout do.
 * - **Authorized, via a link with `auto_capture`** — the return trip of the flow above. Quickpay is
 *   capturing (or has); a capture issued from here could only ever double up on it, so it is a no-op.
 * - **Authorized, via a plain link** — an {@see \Payum\Core\Request\Authorize} flow settling later,
 *   possibly in instalments (`capture_amount`). Capture through the API, as many times as the
 *   authorization allows.
 *
 * `capture_amount` and the "consume only after the API accepted" rule are unchanged; see {@see Amounts}.
 */
class CaptureAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use GatewayAwareTrait;
    use ApiAwareTrait;

    /**
     * @param mixed|Capture $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $model = ArrayObject::ensureArrayObject($request->getModel());

        $paymentId = Details::paymentId($model);
        $payment = $this->api->payments()->getById($paymentId);
        $operations = $payment->operations;

        // The payment is in hand, so keep the balance fresh — same key every fetching action writes.
        $model['balance'] = $payment->balance;

        if (Operations::hasApproved($operations, OperationType::Authorize)) {
            // Authorized through a link that captures by itself: Quickpay is taking the money, and
            // there is no way to issue a capture from here that could not double up on it. Never risk
            // that — if Quickpay's own capture is still queued, the callback (or the next status
            // check) reports it; if it somehow never happens, the payment reports as authorized and
            // the shop sees it.
            if (self::linkAutoCaptures($payment)) {
                return;
            }

            $this->api->payments()->capture(
                $paymentId,
                new CaptureRequest(amount: Amounts::forOperation($model, 'capture_amount')),
            );

            // Only once the API has accepted it — a failed call leaves the instruction in place to retry.
            Amounts::consume($model, 'capture_amount');

            return;
        }

        // An authorize is in flight (3-D Secure, an asynchronous acquirer). A fresh link now would
        // race its outcome; the next call — after the callback, or the customer's return — will see
        // the result.
        if (Operations::hasPending($operations, OperationType::Authorize)) {
            return;
        }

        // A fresh payment, or one whose previous attempt was declined: send the customer to the
        // window, and have Quickpay capture as soon as the card is authorized — that is what
        // "capture" means for a payment nobody has paid yet. The sub-action replies with the
        // redirect, so nothing runs past this line.
        $this->gateway->execute(new CreatePaymentLink(
            $model,
            autoCapture: true,
            token: $request->getToken(),
        ));
    }

    public function supports($request): bool
    {
        return $request instanceof Capture && $request->getModel() instanceof ArrayAccess;
    }

    /**
     * Whether the payment's link was created with `auto_capture`. The SDK's {@see \Setono\Quickpay\Response\Payment\Link}
     * DTO does not model the flag, so it is read off the raw payload the payment is stamped with.
     */
    private static function linkAutoCaptures(Payment $payment): bool
    {
        $link = $payment->raw['link'] ?? null;

        return is_array($link) && true === ($link['auto_capture'] ?? null);
    }
}
