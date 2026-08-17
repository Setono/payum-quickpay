<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Setono\Payum\Quickpay\Operations;
use Setono\Quickpay\Enum\OperationType;
use Setono\Quickpay\Response\Payment\Operation;

class OperationsTest extends TestCase
{
    #[Test]
    public function latestApprovedSkipsTrailingRejectedAndPendingOperations(): void
    {
        $approvedCapture = $this->operation(2, OperationType::Capture, '20000');

        $operations = [
            $this->operation(1, OperationType::Authorize, '20000'),
            $approvedCapture,
            $this->operation(3, OperationType::Refund, '40000'),
            // A pending operation has no status code yet.
            $this->operation(4, OperationType::Refund, null),
        ];

        self::assertSame($approvedCapture, Operations::latestApproved($operations));
    }

    #[Test]
    public function latestApprovedReturnsNullWhenNothingIsApproved(): void
    {
        self::assertNull(Operations::latestApproved([]));
        self::assertNull(Operations::latestApproved([
            $this->operation(1, OperationType::Authorize, '40000'),
            $this->operation(2, OperationType::Authorize, null),
        ]));
    }

    /**
     * "Has this payment ever been authorized?" — regardless of what came after. The last-approved
     * view (latestApproved) says no once a capture follows; this says yes.
     */
    #[Test]
    public function hasApprovedFindsAnApprovedOperationOfTheTypeAnywhereInTheList(): void
    {
        $operations = [
            $this->operation(1, OperationType::Authorize, '20000'),
            $this->operation(2, OperationType::Capture, '20000'),
            $this->operation(3, OperationType::Refund, '40000'),
        ];

        self::assertTrue(Operations::hasApproved($operations, OperationType::Authorize));
        self::assertTrue(Operations::hasApproved($operations, OperationType::Capture));
        self::assertFalse(Operations::hasApproved($operations, OperationType::Refund), 'A rejected refund is not an approved one');
        self::assertFalse(Operations::hasApproved($operations, OperationType::Cancel));
        self::assertFalse(Operations::hasApproved([], OperationType::Authorize));
    }

    #[Test]
    public function hasPendingFindsAnOperationOfTheTypeStillInFlight(): void
    {
        $operations = [
            $this->operation(1, OperationType::Authorize, '20000'),
            new Operation(id: 2, type: OperationType::Capture->value, amount: 100, pending: true),
        ];

        self::assertTrue(Operations::hasPending($operations, OperationType::Capture));
        self::assertFalse(Operations::hasPending($operations, OperationType::Authorize), 'A settled operation is not pending');
        self::assertFalse(Operations::hasPending([], OperationType::Capture));
    }

    /**
     * The newest operation of a type, whatever its outcome — what a caller that just issued one of
     * that type reads the result from. Quickpay appends operations in the order they happen.
     */
    #[Test]
    public function latestOfTypeReturnsTheNewestOperationOfThatTypeRegardlessOfOutcome(): void
    {
        $rejectedCapture = $this->operation(3, OperationType::Capture, '40000');
        $pendingRefund = new Operation(id: 4, type: OperationType::Refund->value, amount: 100, pending: true);

        $operations = [
            $this->operation(1, OperationType::Authorize, '20000'),
            $this->operation(2, OperationType::Capture, '20000'),
            $rejectedCapture,
            $pendingRefund,
        ];

        self::assertSame($rejectedCapture, Operations::latestOfType($operations, OperationType::Capture), 'The rejected one is newer than the approved one');
        self::assertSame($pendingRefund, Operations::latestOfType($operations, OperationType::Refund));
        self::assertNull(Operations::latestOfType($operations, OperationType::Cancel));
        self::assertNull(Operations::latestOfType([], OperationType::Capture));
    }

    #[Test]
    public function isApprovedReflectsTheStatusCode(): void
    {
        self::assertTrue(Operations::isApproved($this->operation(1, OperationType::Capture, '20000')));
        self::assertFalse(Operations::isApproved($this->operation(1, OperationType::Capture, '40000')));
        self::assertFalse(Operations::isApproved($this->operation(1, OperationType::Capture, null)));
    }

    #[Test]
    public function isApprovedOfTypeChecksBothTypeAndApproval(): void
    {
        $approvedCapture = $this->operation(1, OperationType::Capture, '20000');

        self::assertTrue(Operations::isApprovedOfType($approvedCapture, OperationType::Capture));
        self::assertFalse(Operations::isApprovedOfType($approvedCapture, OperationType::Refund));
        self::assertFalse(Operations::isApprovedOfType($this->operation(1, OperationType::Capture, '40000'), OperationType::Capture));
        self::assertFalse(Operations::isApprovedOfType(null, OperationType::Capture));
    }

    private function operation(int $id, OperationType $type, ?string $qpStatusCode = '20000', int $amount = 100): Operation
    {
        return new Operation(
            id: $id,
            type: $type->value,
            amount: $amount,
            qpStatusCode: $qpStatusCode,
        );
    }
}
