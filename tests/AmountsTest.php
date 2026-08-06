<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Tests;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\LogicException;
use PHPUnit\Framework\TestCase;
use Setono\Payum\Quickpay\Amounts;

class AmountsTest extends TestCase
{
    /**
     * @test
     */
    public function shouldFallBackToTheFullAmountWithoutAnOverride(): void
    {
        $details = new ArrayObject(['amount' => 1000]);

        self::assertSame(1000, Amounts::forOperation($details, 'refund_amount'));
    }

    /**
     * @test
     */
    public function shouldPreferTheOverrideWhenPresent(): void
    {
        $details = new ArrayObject(['amount' => 1000, 'refund_amount' => 250]);

        self::assertSame(250, Amounts::forOperation($details, 'refund_amount'));
    }

    /**
     * The override is per operation, so a refund override must not bleed into a capture.
     *
     * @test
     */
    public function shouldOnlyReadItsOwnOverrideKey(): void
    {
        $details = new ArrayObject(['amount' => 1000, 'refund_amount' => 250]);

        self::assertSame(1000, Amounts::forOperation($details, 'capture_amount'));
    }

    /**
     * @test
     */
    public function shouldAcceptNumericStrings(): void
    {
        $details = new ArrayObject(['amount' => '1000']);

        self::assertSame(1000, Amounts::forOperation($details, 'refund_amount'));
    }

    /**
     * @test
     *
     * @dataProvider unusableAmountProvider
     */
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
        yield 'null' => [null, 'must carry a numeric'];
        yield 'not a number' => ['abc', 'must carry a numeric'];
        yield 'zero' => [0, 'must be a positive number'];
        yield 'negative' => [-250, 'must be a positive number'];
    }

    /**
     * @test
     */
    public function shouldThrowWhenNeitherKeyIsPresent(): void
    {
        $this->expectException(LogicException::class);

        Amounts::forOperation(new ArrayObject([]), 'refund_amount');
    }
}
