<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Tests;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Setono\Payum\Quickpay\Amounts;

class AmountsTest extends TestCase
{
    #[Test]
    public function shouldFallBackToTheFullAmountWithoutAnOverride(): void
    {
        $details = new ArrayObject(['amount' => 1000]);

        self::assertSame(1000, Amounts::forOperation($details, 'refund_amount'));
    }

    #[Test]
    public function shouldPreferTheOverrideWhenPresent(): void
    {
        $details = new ArrayObject(['amount' => 1000, 'refund_amount' => 250]);

        self::assertSame(250, Amounts::forOperation($details, 'refund_amount'));
    }

    /**
     * The override is per operation, so a refund override must not bleed into a capture.
     */
    #[Test]
    public function shouldOnlyReadItsOwnOverrideKey(): void
    {
        $details = new ArrayObject(['amount' => 1000, 'refund_amount' => 250]);

        self::assertSame(1000, Amounts::forOperation($details, 'capture_amount'));
    }

    #[Test]
    public function shouldAcceptNumericStrings(): void
    {
        $details = new ArrayObject(['amount' => '1000']);

        self::assertSame(1000, Amounts::forOperation($details, 'refund_amount'));
    }

    #[Test]
    #[DataProvider('unusableAmountProvider')]
    public function shouldThrowOnAnUnusableAmount(mixed $amount, string $expectedMessage): void
    {
        $details = new ArrayObject(['amount' => $amount]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($expectedMessage);

        Amounts::forOperation($details, 'refund_amount');
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function unusableAmountProvider(): iterable
    {
        yield 'null' => [null, 'must carry an integer'];
        yield 'not a number' => ['abc', 'must carry an integer'];
        yield 'zero' => [0, 'must be a positive number'];
        yield 'negative' => [-250, 'must be a positive number'];

        // Amounts are minor units, so a fractional value is always a caller bug — 249.99 can only
        // mean kroner were passed where øre were expected. It used to be silently truncated to 249.
        yield 'float' => [249.99, 'must carry an integer'];
        yield 'whole float' => [250.0, 'must carry an integer'];
        yield 'decimal string' => ['249.99', 'must carry an integer'];
        yield 'bool' => [true, 'must carry an integer'];
    }

    #[Test]
    public function shouldConsumeTheOverrideAndLeaveTheFullAmount(): void
    {
        $details = new ArrayObject(['amount' => 1000, 'refund_amount' => 250]);

        Amounts::consume($details, 'refund_amount');

        self::assertFalse($details->offsetExists('refund_amount'));
        self::assertSame(1000, $details['amount']);
        self::assertSame(1000, Amounts::forOperation($details, 'refund_amount'), 'The next operation falls back to the full amount');
    }

    #[Test]
    public function shouldConsumeNothingWhenNoOverrideWasSet(): void
    {
        $details = new ArrayObject(['amount' => 1000]);

        Amounts::consume($details, 'refund_amount');

        self::assertSame(1000, $details['amount']);
    }

    #[Test]
    public function shouldThrowWhenNeitherKeyIsPresent(): void
    {
        $this->expectException(LogicException::class);

        Amounts::forOperation(new ArrayObject([]), 'refund_amount');
    }
}
