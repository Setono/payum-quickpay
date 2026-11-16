<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Exception;

use Payum\Core\Exception\LogicException;

/**
 * An order prefix and a payment number that don't make an order id Quickpay accepts — thrown by
 * {@see \Setono\Payum\Quickpay\OrderId::of()}, and so by the convert before anything is sent to Quickpay.
 *
 * It carries both parts and the order id they make, so an integration can word its own message (an admin
 * form in the admin's language, say). A {@see LogicException} — Payum's, which is PHP's — like the exception
 * the convert threw for it before, so existing catches keep working.
 */
final class InvalidOrderId extends LogicException
{
    private function __construct(
        public readonly string $prefix,
        public readonly string $number,
        public readonly string $orderId,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function of(string $prefix, string $number): self
    {
        $orderId = $prefix . $number;

        if ('' === $number) {
            return new self($prefix, $number, $orderId, sprintf(
                'There is no Quickpay order id without a payment number: under the order prefix "%s" it would be '
                . 'the bare prefix, the same for every payment.',
                $prefix,
            ));
        }

        return new self($prefix, $number, $orderId, sprintf(
            'The Quickpay order id "%s" (%d characters) is not one Quickpay accepts: 4–20 characters of letters, '
            . 'digits, space, ".", "_" and "-". It is built from the order prefix "%s" and the payment number "%s" '
            . '— adjust the prefix so the two together fit.',
            $orderId,
            preg_match_all('/./su', $orderId),
            $prefix,
            $number,
        ));
    }
}
