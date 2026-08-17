<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Exception;

use Payum\Core\Exception\RuntimeException;
use Setono\Quickpay\Enum\OperationType;
use Setono\Quickpay\Response\Payment\Operation;
use Setono\Quickpay\Response\Payment\Payment;

/**
 * A capture, refund or cancel is still in flight on the payment, so the gateway will not issue another
 * money operation until it has settled.
 *
 * Operations run asynchronously by default: Quickpay queues them and answers before the acquirer has,
 * and the outcome arrives via the callback or the next re-fetch. Until then the payment's `balance` and
 * `state` are the pre-operation ones, and anything issued on top races the outcome — a `Capture` retried
 * after a timeout would take the money twice, a `Refund` retried would refund the (stale) balance
 * again, a `Cancel` would race a capture. So the gateway issues one money operation at a time: while
 * any of the three is pending, the next one throws this instead of being queued. Wait for the callback
 * (or a `Sync`/`GetStatus`) to report the outcome, or configure the gateway with `synchronized`, which
 * has no in-flight window at all.
 *
 * A {@see RuntimeException}: the request is fine, the moment is not. Its sibling
 * {@see OperationRejectedException} is the outcome that DID arrive and was a decline.
 */
final class OperationPendingException extends RuntimeException
{
    /**
     * The operations that move money — the ones that must not overlap. An authorize in flight is the
     * entry-point actions' concern; a `pending` type the SDK does not model (`null`) is not one of ours.
     */
    private const MONEY_OPERATIONS = [OperationType::Capture, OperationType::Refund, OperationType::Cancel];

    private function __construct(
        string $message,
        private readonly int $paymentId,
        private readonly Operation $operation,
    ) {
        parent::__construct($message);
    }

    /**
     * Throws if a capture, refund or cancel is pending on the payment.
     *
     * @throws self
     */
    public static function assertNoneInFlight(int $paymentId, Payment $payment): void
    {
        // Newest first: the operation reported is the one most recently queued.
        foreach (array_reverse($payment->operations) as $operation) {
            $type = $operation->type();

            if (!$operation->pending || !in_array($type, self::MONEY_OPERATIONS, true)) {
                continue;
            }

            throw new self(
                sprintf(
                    'A %s of Quickpay payment %d is still pending; issuing another operation now would race its outcome. '
                    . 'Wait for the callback (or a Sync/GetStatus) to report it, or configure the gateway with "synchronized".',
                    $operation->type,
                    $paymentId,
                ),
                $paymentId,
                $operation,
            );
        }
    }

    public function getPaymentId(): int
    {
        return $this->paymentId;
    }

    /**
     * The pending operation, as Quickpay returned it.
     */
    public function getOperation(): Operation
    {
        return $this->operation;
    }
}
