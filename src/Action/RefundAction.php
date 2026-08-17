<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Action;

use ArrayAccess;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\LogicException;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\Refund;
use Setono\Payum\Quickpay\Action\Api\ApiAwareTrait;
use Setono\Payum\Quickpay\Amounts;
use Setono\Payum\Quickpay\Details;
use Setono\Payum\Quickpay\Exception\OperationPendingException;
use Setono\Payum\Quickpay\Exception\OperationRejectedException;
use Setono\Quickpay\Enum\OperationType;
use Setono\Quickpay\Request\Payment\RefundRequest;
use Setono\Quickpay\Response\Payment\Payment;

class RefundAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use GatewayAwareTrait;
    use ApiAwareTrait;

    /**
     * @param mixed|Refund $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $model = ArrayObject::ensureArrayObject($request->getModel());

        $paymentId = Details::paymentId($model);
        $payment = $this->api->payments()->getById($paymentId);

        // Keep it fresh for the caller while we have it — same key every fetching action writes.
        $model['balance'] = $payment->balance;

        // One money operation at a time: a capture or refund still in flight has not settled, so the
        // balance above is the pre-operation one, and a refund on top would race it — retried after a
        // timeout, it refunds twice.
        OperationPendingException::assertNoneInFlight($paymentId, $payment);

        $refunded = $this->api->payments()->refund(
            $paymentId,
            new RefundRequest(amount: self::resolveAmount($model, $paymentId, $payment)),
        );

        // Asynchronously the returned payment is a snapshot with the refund still pending and nothing
        // to read; synchronized, it carries the outcome — and a decline is a 2xx.
        OperationRejectedException::assertNotRejected($paymentId, $refunded, OperationType::Refund);

        // Only once the API has accepted it — a failed call leaves the instruction in place to retry.
        Amounts::consume($model, 'refund_amount');
    }

    public function supports($request): bool
    {
        return $request instanceof Refund && $request->getModel() instanceof ArrayAccess;
    }

    /**
     * A `Refund` with no explicit amount refunds whatever is still refundable.
     *
     * The obvious default — the payment's full `amount` — is wrong the moment anything has already been
     * refunded, including directly in the Quickpay manager: you cannot refund more than is captured, so
     * Quickpay rejects it outright. The maximal refundable amount is the payment's `balance` (captured
     * minus refunded), so that is what an unqualified "refund this payment" means.
     *
     * An explicit `refund_amount` is used as given — the caller's instruction is never second-guessed
     * against the balance; Quickpay validates it.
     *
     * @param ArrayObject<string, mixed> $model
     *
     * @throws LogicException if nothing is left to refund
     */
    private static function resolveAmount(ArrayObject $model, int $paymentId, Payment $payment): int
    {
        if ($model->offsetExists('refund_amount')) {
            return Amounts::forOperation($model, 'refund_amount');
        }

        $balance = $payment->balance;

        if (null === $balance || $balance <= 0) {
            throw new LogicException(sprintf(
                'There is nothing left to refund on Quickpay payment %d: the balance is %s. A payment '
                . 'is refundable only up to what is captured and not yet refunded.',
                $paymentId,
                null === $balance ? 'unknown' : (string) $balance,
            ));
        }

        return $balance;
    }
}
