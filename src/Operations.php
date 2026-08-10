<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay;

use Setono\Quickpay\Enum\OperationType;
use Setono\Quickpay\Response\Payment\Operation;

/**
 * Stateless helpers over a payment's list of {@see Operation}s.
 *
 * This replaces the behavior that used to live on the deleted `QuickpayPayment` /
 * `QuickpayPaymentOperation` models — the SDK response DTOs are readonly data holders without
 * behavior of their own.
 */
final class Operations
{
    /**
     * Quickpay's status code for an approved operation.
     */
    public const APPROVED_STATUS_CODE = '20000';

    private function __construct()
    {
    }

    /**
     * @param list<Operation> $operations
     */
    public static function latest(array $operations): ?Operation
    {
        if ([] === $operations) {
            return null;
        }

        return $operations[array_key_last($operations)];
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
            if (self::isApproved($operation)) {
                return $operation;
            }
        }

        return null;
    }

    public static function isApproved(Operation $operation): bool
    {
        return self::APPROVED_STATUS_CODE === $operation->qpStatusCode;
    }

    public static function isApprovedOfType(?Operation $operation, OperationType $type): bool
    {
        return null !== $operation && $type === $operation->type() && self::isApproved($operation);
    }

    /**
     * @param list<Operation> $operations
     */
    public static function isLatestApproved(array $operations, OperationType $type): bool
    {
        return self::isApprovedOfType(self::latest($operations), $type);
    }

    /**
     * The amount of the most recent approved authorize operation, or 0 if there is none.
     *
     * @param list<Operation> $operations
     */
    public static function authorizedAmount(array $operations): int
    {
        foreach (array_reverse($operations) as $operation) {
            if (OperationType::Authorize === $operation->type() && self::isApproved($operation)) {
                return (int) $operation->amount;
            }
        }

        return 0;
    }
}
