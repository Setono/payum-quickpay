<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay;

use Setono\Payum\Quickpay\Exception\InvalidOrderId;
use Setono\Quickpay\Request\Payment\CreatePaymentRequest;

/**
 * The Quickpay order id of a payment, both ways: the `order_prefix` gateway option followed by the Payum
 * payment number, and the number read back from an order id.
 *
 * Quickpay accepts 4–20 characters of letters, digits, space, `.`, `_` and `-` (the SDK's
 * {@see CreatePaymentRequest::ORDER_ID_PATTERN}, verified live) and requires the order id to be unique on
 * the account. {@see Action\ConvertPaymentAction} builds every order id here, and {@see GatewayOptions} rejects an
 * order prefix that can never start one. An integration checking a prefix against its own payment numbers,
 * or resolving a callback sent to the account-wide callback url, which carries no Payum token, uses the
 * same two functions.
 */
final class OrderId
{
    /**
     * The longest order prefix that can start an order id: the number needs at least one of its 20 characters
     */
    public const MAX_PREFIX_LENGTH = 19;

    /**
     * What an order prefix may hold: up to {@see self::MAX_PREFIX_LENGTH} of the characters Quickpay accepts
     * in an order id
     */
    public const PREFIX_PATTERN = '/^[A-Za-z0-9 ._-]{0,19}$/';

    private function __construct()
    {
    }

    /**
     * The order id of the payment numbered `$number` under the order prefix `$prefix`
     *
     * @throws InvalidOrderId when Quickpay would refuse it, or the number is empty: the order id would then be
     *                        the bare prefix, the same for every payment
     */
    public static function of(string $prefix, string $number): string
    {
        $orderId = $prefix . $number;

        if ('' === $number || 1 !== preg_match(CreatePaymentRequest::ORDER_ID_PATTERN, $orderId)) {
            throw InvalidOrderId::of($prefix, $number);
        }

        return $orderId;
    }

    /**
     * The payment number in `$orderId` under the order prefix `$prefix`, the inverse of {@see self::of()}: null
     * when the order id doesn't start with the prefix, or nothing follows it. An empty prefix gives the order id
     * itself.
     */
    public static function numberIn(string $orderId, string $prefix): ?string
    {
        if (!str_starts_with($orderId, $prefix) || \strlen($orderId) === \strlen($prefix)) {
            return null;
        }

        return substr($orderId, \strlen($prefix));
    }
}
