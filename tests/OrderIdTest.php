<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Tests;

use Payum\Core\Exception\LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Setono\Payum\Quickpay\Exception\InvalidOrderId;
use Setono\Payum\Quickpay\OrderId;
use Setono\Quickpay\Request\Payment\CreatePaymentRequest;

final class OrderIdTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function validOrderIds(): iterable
    {
        yield 'a prefix and a Sylius order number' => ['qp_', '000000123', 'qp_000000123'];
        yield 'no prefix' => ['', '000000123', '000000123'];
        yield 'the shortest order id' => ['qp_', '1', 'qp_1'];
        yield 'the longest order id' => ['qp_', str_repeat('9', 17), 'qp_' . str_repeat('9', 17)];
        yield 'the longest prefix' => [str_repeat('p', 19), '1', str_repeat('p', 19) . '1'];
        yield 'every character Quickpay accepts' => ['Az 09._-', 'Za 90-_.', 'Az 09._-Za 90-_.'];
    }

    #[Test]
    #[DataProvider('validOrderIds')]
    public function shouldBuildTheOrderIdFromThePrefixAndTheNumber(string $prefix, string $number, string $orderId): void
    {
        self::assertSame($orderId, OrderId::of($prefix, $number));
    }

    /**
     * The round trip: the number read back from an order id is the number it was built from
     */
    #[Test]
    #[DataProvider('validOrderIds')]
    public function shouldReadTheNumberBackFromTheOrderIdItBuilt(string $prefix, string $number): void
    {
        self::assertSame($number, OrderId::numberIn(OrderId::of($prefix, $number), $prefix));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function invalidOrderIds(): iterable
    {
        yield 'too short' => ['qp', '1', 'The Quickpay order id "qp1" (3 characters) is not one Quickpay accepts'];
        yield 'too long' => ['qp_shop_2026_', '000000123', 'The Quickpay order id "qp_shop_2026_000000123" (22 characters) is not one Quickpay accepts'];
        yield 'a character Quickpay refuses in the prefix' => ['qp#', '000000123', 'The Quickpay order id "qp#000000123" (12 characters)'];
        yield 'a character Quickpay refuses in the number' => ['qp_', '2026/123', 'built from the order prefix "qp_" and the payment number "2026/123"'];
        yield 'a character outside ASCII, counted once' => ['øl_', '000000123', 'The Quickpay order id "øl_000000123" (12 characters)'];
        yield 'no number' => ['qp_shop_', '', 'There is no Quickpay order id without a payment number: under the order prefix "qp_shop_"'];
    }

    #[Test]
    #[DataProvider('invalidOrderIds')]
    public function shouldRefuseAnOrderIdQuickpayRefuses(string $prefix, string $number, string $message): void
    {
        try {
            OrderId::of($prefix, $number);
        } catch (InvalidOrderId $e) {
            self::assertStringContainsString($message, $e->getMessage());
            self::assertSame($prefix, $e->prefix);
            self::assertSame($number, $e->number);
            self::assertSame($prefix . $number, $e->orderId);
            self::assertInstanceOf(LogicException::class, $e, 'The convert threw a Payum LogicException before, so existing catches keep working');

            return;
        }

        self::fail('The order id was accepted');
    }

    #[Test]
    public function shouldSayWhyQuickpayRefusesTheOrderIdAndWhatToAdjust(): void
    {
        $this->expectExceptionMessage(
            'The Quickpay order id "qp_shop_2026_000000123" (22 characters) is not one Quickpay accepts: 4–20 characters '
            . 'of letters, digits, space, ".", "_" and "-". It is built from the order prefix "qp_shop_2026_" and the '
            . 'payment number "000000123" — adjust the prefix so the two together fit.',
        );

        OrderId::of('qp_shop_2026_', '000000123');
    }

    #[Test]
    public function shouldSayThereIsNoOrderIdWithoutANumber(): void
    {
        $this->expectExceptionMessage(
            'There is no Quickpay order id without a payment number: under the order prefix "qp_shop_" it would be the '
            . 'bare prefix, the same for every payment.',
        );

        OrderId::of('qp_shop_', '');
    }

    #[Test]
    public function shouldReadNoNumberFromAnOrderIdUnderAnotherPrefix(): void
    {
        self::assertNull(OrderId::numberIn('shop_000000123', 'qp_'));
    }

    #[Test]
    public function shouldReadNoNumberFromAnOrderIdThatIsOnlyThePrefix(): void
    {
        self::assertNull(OrderId::numberIn('qp_shop', 'qp_shop'));
    }

    #[Test]
    public function shouldReadTheWholeOrderIdUnderNoPrefix(): void
    {
        self::assertSame('qp_000000123', OrderId::numberIn('qp_000000123', ''));
    }

    #[Test]
    public function shouldReadTheNumberOnlyFromTheStartOfTheOrderId(): void
    {
        self::assertNull(OrderId::numberIn('000000123qp_', 'qp_'));
        self::assertSame('qp_000000123', OrderId::numberIn('qp_qp_000000123', 'qp_'));
    }

    /**
     * The prefix rule holds exactly the characters the SDK's order id rule accepts: each printable ASCII
     * character is allowed in a prefix exactly when an order id starting with it is one Quickpay accepts.
     */
    #[Test]
    public function shouldAllowInAPrefixExactlyTheCharactersQuickpayAcceptsInAnOrderId(): void
    {
        for ($code = 0x20; $code <= 0x7E; ++$code) {
            $character = \chr($code);

            self::assertSame(
                1 === preg_match(CreatePaymentRequest::ORDER_ID_PATTERN, $character . '000'),
                1 === preg_match(OrderId::PREFIX_PATTERN, $character),
                sprintf('The character "%s"', $character),
            );
        }
    }

    #[Test]
    public function shouldAllowAPrefixUpToTheLengthThatLeavesRoomForANumber(): void
    {
        self::assertSame(1, preg_match(OrderId::PREFIX_PATTERN, ''));
        self::assertSame(1, preg_match(OrderId::PREFIX_PATTERN, str_repeat('p', OrderId::MAX_PREFIX_LENGTH)));
        self::assertSame(0, preg_match(OrderId::PREFIX_PATTERN, str_repeat('p', OrderId::MAX_PREFIX_LENGTH + 1)));
        self::assertSame(str_repeat('p', OrderId::MAX_PREFIX_LENGTH) . '1', OrderId::of(str_repeat('p', OrderId::MAX_PREFIX_LENGTH), '1'));
    }
}
