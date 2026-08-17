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
use Setono\Payum\Quickpay\Exception\OperationPendingException;
use Setono\Payum\Quickpay\Exception\OperationRejectedException;
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
 *   The one exception is Quickpay's own capture having been **declined** (an acquirer decline at
 *   capture time — Quickpay attempts it once and does not retry): the money is still only held, and
 *   a programmatic `Capture` — the merchant settling — captures through the API like on a plain link.
 *   The return trip itself (a token-carrying `Capture`) still moves no money: the customer has to
 *   land somewhere, and the shop sees the payment as `authorized`.
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

        // The payment is in hand, so keep the balance fresh — same key every fetching action writes.
        $model['balance'] = $payment->balance;

        if ($payment->hasApprovedOperation(OperationType::Authorize)) {
            // Authorized through a link that captures by itself: Quickpay is taking the money, and
            // there is no way to issue a capture from here that could not double up on it — unless
            // Quickpay's own capture has failed for good.
            if (true === $payment->link?->autoCapture && !self::quickpayGaveUpCapturing($payment, $request)) {
                return;
            }

            // One money operation at a time: a capture, refund or cancel still in flight has not
            // settled, and another capture on top would race it — retried after a timeout, it takes
            // the money twice.
            OperationPendingException::assertNoneInFlight($paymentId, $payment);

            $captured = $this->api->payments()->capture(
                $paymentId,
                new CaptureRequest(amount: Amounts::forOperation($model, 'capture_amount')),
                // Quickpay would report this capture to the account-wide callback url (empty by
                // default). Naming the payment's own notify url — the one its link already carries —
                // routes the callback to the same per-payment endpoint as the payment window's.
                callbackUrl: Details::callbackUrl($model),
            );

            // Asynchronously the returned payment is a snapshot with the capture still pending and
            // nothing to read; synchronized, it carries the outcome — and a decline is a 2xx.
            OperationRejectedException::assertNotRejected($paymentId, $captured, OperationType::Capture);

            // Only once the API has accepted it — a failed call leaves the instruction in place to retry.
            Amounts::consume($model, 'capture_amount');

            return;
        }

        // An authorize is in flight (3-D Secure, an asynchronous acquirer). A fresh link now would
        // race its outcome; the next call — after the callback, or the customer's return — will see
        // the result.
        if ($payment->hasPendingOperation(OperationType::Authorize)) {
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
     * On a link that captures by itself, whether Quickpay's own capture is over and done with — and
     * declined — so that only a capture issued from here can still settle the payment.
     *
     * While a capture is approved or pending, or none is recorded yet (the moment right after the
     * authorization, before Quickpay has recorded the capture it is already making), the answer is no:
     * anything issued from here could double up. Once the newest capture is a completed decline
     * (an acquirer "do not honor" at capture time), Quickpay will not try again; without this the
     * payment was stuck — `authorized` forever, and every `Capture` a silent no-op, so nothing short
     * of the Quickpay manager could ever take the money.
     *
     * Even then the return trip of the flow — the token-carrying `Capture` Payum's controller
     * re-executes when the customer comes back — stays a no-op: the customer must land somewhere,
     * and the shop sees `authorized`, which is the truth. Settling is the merchant's call: a
     * programmatic `Capture` (no token — the shop's own code, or a state-machine hook), and that one
     * captures.
     */
    private static function quickpayGaveUpCapturing(Payment $payment, Capture $request): bool
    {
        if ($payment->hasApprovedOperation(OperationType::Capture) ||
            $payment->hasPendingOperation(OperationType::Capture) ||
            null === $payment->latestOperationOfType(OperationType::Capture)) {
            return false;
        }

        return null === $request->getToken();
    }
}
