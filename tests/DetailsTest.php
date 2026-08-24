<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Tests;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Setono\Payum\Quickpay\Details;

class DetailsTest extends TestCase
{
    /**
     * "Is there a payment at Quickpay?" — null counts as absent, the way ConvertPaymentAction reads it
     * (isset), because a consumer's own Convert or a storage round trip may leave `null` behind for
     * "not created yet". Payum's ArrayObject::offsetExists() alone says true for that.
     */
    #[Test]
    public function shouldTellWhetherAnIdIsPresent(): void
    {
        self::assertTrue(Details::hasPaymentId(new ArrayObject(['quickpayPaymentId' => 1001])));
        self::assertTrue(Details::hasPaymentId(new ArrayObject(['quickpayPaymentId' => '1001'])));
        self::assertTrue(Details::hasPaymentId(new ArrayObject(['quickpayPaymentId' => 'abc'])), 'Present but unusable is still present — paymentId() is what complains');
        self::assertFalse(Details::hasPaymentId(new ArrayObject(['quickpayPaymentId' => null])));
        self::assertFalse(Details::hasPaymentId(new ArrayObject([])));
    }

    /**
     * The payment's own notify url, for routing an operation's callback — or null when the payment never
     * went through the window here (no token minted, nothing to name).
     */
    #[Test]
    public function shouldReadTheCallbackUrl(): void
    {
        self::assertSame('https://shop.example/notify?payum_token=abc', Details::callbackUrl(new ArrayObject(['callback_url' => 'https://shop.example/notify?payum_token=abc'])));
        self::assertNull(Details::callbackUrl(new ArrayObject([])));
        self::assertNull(Details::callbackUrl(new ArrayObject(['callback_url' => ''])));
        self::assertNull(Details::callbackUrl(new ArrayObject(['callback_url' => null])));
    }

    #[Test]
    public function shouldReadTheId(): void
    {
        self::assertSame(1001, Details::paymentId(new ArrayObject(['quickpayPaymentId' => 1001])));
    }

    /**
     * Details commonly survive a serialization round trip in the consumer's storage, which may well
     * stringify the id on the way.
     */
    #[Test]
    public function shouldAcceptANumericString(): void
    {
        self::assertSame(1001, Details::paymentId(new ArrayObject(['quickpayPaymentId' => '1001'])));
    }

    /**
     * `(int) $details['quickpayPaymentId']` on a missing key would be `(int) null = 0` — an API call
     * to `GET /payments/0` and a NotFoundException blaming an id nobody ever set. This is the clear
     * exception that replaces it.
     *
     * @param array<string, mixed> $details
     */
    #[Test]
    #[DataProvider('unusableIdProvider')]
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
