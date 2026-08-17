<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Exception;

use Payum\Core\Exception\RuntimeException;
use Setono\Payum\Quickpay\Operations;
use Setono\Quickpay\Enum\OperationType;
use Setono\Quickpay\Response\Payment\Operation;
use Setono\Quickpay\Response\Payment\Payment;

/**
 * Quickpay processed a capture, refund or cancel and declined it.
 *
 * A declined operation is not an HTTP error: the API answers 2xx with the payment, and the outcome
 * sits on the operation (`qp_status_code` other than 20000, `pending` false). By default the
 * operations run asynchronously, so the response only carries the operation as pending and the
 * outcome arrives later, via the callback or a re-fetch. With the `synchronized` option Quickpay
 * waits for the acquirer and answers with the completed operation — and "completed" includes
 * "declined". Without this exception a synchronized decline returned from the action exactly like an
 * approval, and a caller who had chosen `synchronized` precisely to learn the outcome was told
 * nothing.
 *
 * It is a {@see RuntimeException}, not a {@see \Payum\Core\Exception\LogicException}: nothing about the
 * request was wrong, the acquirer said no.
 */
final class OperationRejectedException extends RuntimeException
{
    private function __construct(
        string $message,
        private readonly int $paymentId,
        private readonly Operation $operation,
    ) {
        parent::__construct($message);
    }

    /**
     * Throws if the most recent operation of the given type on the payment Quickpay returned has an
     * outcome and it is not approved.
     *
     * Only an operation that has finished processing (`pending` false) can be declined; the pending
     * operation an asynchronous call returns has no outcome yet and passes. So this fires for a
     * synchronized call, and for the rare asynchronous one Quickpay decides up front — never for a
     * still-running one.
     *
     * @throws self
     */
    public static function assertNotRejected(int $paymentId, Payment $payment, OperationType $type): void
    {
        $operation = Operations::latestOfType($payment->operations, $type);

        if (null === $operation || $operation->pending || Operations::isApproved($operation)) {
            return;
        }

        throw new self(
            sprintf(
                'Quickpay declined the %s of payment %d: %s%s.',
                $type->value,
                $paymentId,
                null !== $operation->qpStatusCode ? sprintf('status %s', $operation->qpStatusCode) : 'no status code',
                null !== $operation->qpStatusMsg && '' !== $operation->qpStatusMsg ? sprintf(' (%s)', $operation->qpStatusMsg) : '',
            ),
            $paymentId,
            $operation,
        );
    }

    public function getPaymentId(): int
    {
        return $this->paymentId;
    }

    /**
     * The declined operation as Quickpay returned it: type, amount, `qpStatusCode`/`qpStatusMsg` and
     * the acquirer's `aqStatusCode`/`aqStatusMsg`.
     */
    public function getOperation(): Operation
    {
        return $this->operation;
    }
}
