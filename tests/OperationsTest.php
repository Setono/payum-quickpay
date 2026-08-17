<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Tests;

use PHPUnit\Framework\TestCase;
use Setono\Payum\Quickpay\Operations;
use Setono\Quickpay\Enum\OperationType;
use Setono\Quickpay\Response\Payment\Operation;

class OperationsTest extends TestCase
{
    /**
     * @test
     */
    public function latestReturnsNullForNoOperations(): void
    {
        self::assertNull(Operations::latest([]));
    }

    /**
     * @test
     */
    public function latestReturnsTheLastOperation(): void
    {
        $first = $this->operation(1, OperationType::Authorize);
        $last = $this->operation(2, OperationType::Capture);

        self::assertSame($last, Operations::latest([$first, $last]));
    }

    /**
     * @test
     */
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

    /**
     * @test
     */
    public function latestApprovedReturnsNullWhenNothingIsApproved(): void
    {
        self::assertNull(Operations::latestApproved([]));
        self::assertNull(Operations::latestApproved([
            $this->operation(1, OperationType::Authorize, '40000'),
            $this->operation(2, OperationType::Authorize, null),
        ]));
    }

    /**
     * @test
     */
    public function isApprovedReflectsTheStatusCode(): void
    {
        self::assertTrue(Operations::isApproved($this->operation(1, OperationType::Capture, '20000')));
        self::assertFalse(Operations::isApproved($this->operation(1, OperationType::Capture, '40000')));
        self::assertFalse(Operations::isApproved($this->operation(1, OperationType::Capture, null)));
    }

    /**
     * @test
     */
    public function isApprovedOfTypeChecksBothTypeAndApproval(): void
    {
        $approvedCapture = $this->operation(1, OperationType::Capture, '20000');

        self::assertTrue(Operations::isApprovedOfType($approvedCapture, OperationType::Capture));
        self::assertFalse(Operations::isApprovedOfType($approvedCapture, OperationType::Refund));
        self::assertFalse(Operations::isApprovedOfType($this->operation(1, OperationType::Capture, '40000'), OperationType::Capture));
        self::assertFalse(Operations::isApprovedOfType(null, OperationType::Capture));
    }

    /**
     * @test
     */
    public function isLatestApprovedLooksAtTheLastOperation(): void
    {
        $operations = [
            $this->operation(1, OperationType::Authorize, '20000'),
            $this->operation(2, OperationType::Capture, '20000'),
        ];

        self::assertTrue(Operations::isLatestApproved($operations, OperationType::Capture));
        self::assertFalse(Operations::isLatestApproved($operations, OperationType::Authorize));
        self::assertFalse(Operations::isLatestApproved([], OperationType::Capture));
    }

    /**
     * @test
     */
    public function authorizedAmountReturnsTheMostRecentApprovedAuthorizeAmount(): void
    {
        $operations = [
            $this->operation(1, OperationType::Authorize, '20000', 50),
            $this->operation(2, OperationType::Authorize, '20000', 100),
            $this->operation(3, OperationType::Capture, '20000', 100),
        ];

        self::assertSame(100, Operations::authorizedAmount($operations));
    }

    /**
     * @test
     */
    public function authorizedAmountIsZeroWithoutAnApprovedAuthorize(): void
    {
        self::assertSame(0, Operations::authorizedAmount([]));
        self::assertSame(0, Operations::authorizedAmount([
            $this->operation(1, OperationType::Authorize, '40000', 100),
            $this->operation(2, OperationType::Capture, '20000', 100),
        ]));
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
