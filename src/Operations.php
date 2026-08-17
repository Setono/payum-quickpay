<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay;

use Setono\Quickpay\Enum\OperationType;
use Setono\Quickpay\Response\Payment\Operation;

/**
 * Stateless helpers over a payment's list of {@see Operation}s — the questions the actions ask that
 * the SDK's own helpers do not answer as such.
 *
 * "Is this operation approved / of that type" is the SDK's ({@see Operation::isApproved()} — not
 * pending AND status `20000` — and {@see Operation::isOfType()}), and everything here builds on those.
 * What the SDK's {@see \Setono\Quickpay\Response\Payment\Payment} helpers offer is either the wrong
 * shape for the actions (`latestOperation()` regardless of type, `hasPendingOperation()` regardless of
 * type, summed amounts) or answers a question they do not ask; the per-type, last-approved views live
 * here.
 */
final class Operations
{
    private function __construct()
    {
    }

    /**
     * The most recent approved operation, or null if nothing has been approved (yet).
     *
     * An operation list may end in rejected or still-pending attempts — a failed refund, an
     * asynchronous capture that has not settled. A status decision must not let such a trailing
     * attempt mask what actually happened to the money, so it reads the last operation that DID
     * succeed rather than the last one recorded.
     *
     * @param list<Operation> $operations
     */
    public static function latestApproved(array $operations): ?Operation
    {
        foreach (array_reverse($operations) as $operation) {
            if ($operation->isApproved()) {
                return $operation;
            }
        }

        return null;
    }

    /**
     * The most recent operation of the given type — approved, rejected or still pending — or null if
     * none was ever recorded. This is the operation whose outcome a caller that just issued one of
     * that type wants to read: Quickpay appends operations in the order they happen, so the last one
     * of a type is the attempt made last.
     *
     * @param list<Operation> $operations
     */
    public static function latestOfType(array $operations, OperationType $type): ?Operation
    {
        foreach (array_reverse($operations) as $operation) {
            if ($operation->isOfType($type)) {
                return $operation;
            }
        }

        return null;
    }

    /**
     * Whether any operation of the given type has been approved — regardless of what came after it.
     * "Has this payment ever been authorized?" is this question; the last-approved-operation view
     * that drives the status marks would say no once a capture follows.
     *
     * @param list<Operation> $operations
     */
    public static function hasApproved(array $operations, OperationType $type): bool
    {
        foreach ($operations as $operation) {
            if ($operation->isOfType($type) && $operation->isApproved()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an operation of the given type is still in flight: queued asynchronously (or, for an
     * authorize, held up in 3-D Secure) and without an outcome yet. Acting on the payment while one
     * is pending — creating another link, issuing another capture — would race the outcome.
     *
     * @param list<Operation> $operations
     */
    public static function hasPending(array $operations, OperationType $type): bool
    {
        foreach ($operations as $operation) {
            if ($operation->isOfType($type) && $operation->pending) {
                return true;
            }
        }

        return false;
    }

    public static function isApprovedOfType(?Operation $operation, OperationType $type): bool
    {
        return null !== $operation && $operation->isOfType($type) && $operation->isApproved();
    }
}
