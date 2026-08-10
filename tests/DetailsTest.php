<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Tests;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\LogicException;
use PHPUnit\Framework\TestCase;
use Setono\Payum\Quickpay\Details;

class DetailsTest extends TestCase
{
    /**
     * @test
     */
    public function shouldReadTheId(): void
    {
        self::assertSame(1001, Details::paymentId(new ArrayObject(['quickpayPaymentId' => 1001])));
    }

    /**
     * Details commonly survive a serialization round trip in the consumer's storage, which may well
     * stringify the id on the way.
     *
     * @test
     */
    public function shouldAcceptANumericString(): void
    {
        self::assertSame(1001, Details::paymentId(new ArrayObject(['quickpayPaymentId' => '1001'])));
    }

    /**
     * `(int) $details['quickpayPaymentId']` on a missing key would be `(int) null = 0` — an API call
     * to `GET /payments/0` and a NotFoundException blaming an id nobody ever set. This is the clear
     * exception that replaces it.
     *
     * @test
     *
     * @dataProvider unusableIdProvider
     *
     * @param array<string, mixed> $details
     */
    public function shouldThrowOnAnUnusableId(array $details): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('quickpayPaymentId');

        Details::paymentId(new ArrayObject($details));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unusableIdProvider(): iterable
    {
        yield 'missing' => [[]];
        yield 'null' => [['quickpayPaymentId' => null]];
        yield 'empty string' => [['quickpayPaymentId' => '']];
        yield 'not a number' => [['quickpayPaymentId' => 'abc']];
        yield 'zero' => [['quickpayPaymentId' => 0]];
        yield 'negative' => [['quickpayPaymentId' => -1]];
        yield 'negative string' => [['quickpayPaymentId' => '-1']];
        yield 'float' => [['quickpayPaymentId' => 10.5]];
    }
}
