<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay;

use ArrayAccess;
use Payum\Core\Exception\LogicException;

/**
 * Resolves the amount an operation should be issued for.
 *
 * Payum's `Capture` and `Refund` requests carry no amount of their own — the details array is the only
 * channel a caller has — so a partial capture or refund is expressed by setting an override key on the
 * details before executing the request:
 *
 *     $details['refund_amount'] = 250;
 *     $gateway->execute(new Refund($details));
 *
 * With no override the full `amount` is used, which is what the gateway has always done.
 *
 * Amounts are integer minor units throughout, matching Quickpay and Payum both.
 */
final class Amounts
{
    private function __construct()
    {
    }

    /**
     * The payment's own `amount` — what the payment link is created for, and what an operation falls
     * back to without an override. Read with the same strictness as {@see self::forOperation()}: the
     * link amount is what Quickpay authorizes, so a silently truncated `'249.99'` there is exactly as
     * wrong as in a capture.
     *
     * @param ArrayAccess<string, mixed> $details
     *
     * @throws LogicException if `amount` is not a usable positive integer
     */
    public static function amount(ArrayAccess $details): int
    {
        return self::positiveInteger(
            $details['amount'] ?? null,
            'The payment details must carry an integer "amount" (minor units); got %s.',
            'The "amount" must be a positive number of minor units, got %d.',
        );
    }

    /**
     * Only integers (and integer strings, the shape a serialization round trip may produce) are
     * accepted. A fractional value is always a caller bug — amounts are minor units, so `249.99`
     * can only mean someone passed kroner where øre were expected — and the old `is_numeric()` +
     * `(int)` combination silently truncated it to 249 instead of saying so.
     *
     * @param ArrayAccess<string, mixed> $details
     * @param string $overrideKey the details key holding a partial amount, if the caller set one
     *
     * @throws LogicException if neither the override nor `amount` is a usable positive integer
     */
    public static function forOperation(ArrayAccess $details, string $overrideKey): int
    {
        return self::positiveInteger(
            $details->offsetExists($overrideKey) ? $details[$overrideKey] : ($details['amount'] ?? null),
            sprintf('The payment details must carry an integer "%s" or "amount" (minor units) to operate on; got %%s.', $overrideKey),
            sprintf(
                'The amount to operate on must be a positive number of minor units, got %%d. A partial '
                . 'capture or refund is set with the "%s" details key.',
                $overrideKey,
            ),
        );
    }

    /**
     * @param string $notAnIntegerMessage sprintf template with one `%s` for the offending value
     * @param string $notPositiveMessage sprintf template with one `%d` for the offending amount
     *
     * @throws LogicException
     */
    private static function positiveInteger(mixed $amount, string $notAnIntegerMessage, string $notPositiveMessage): int
    {
        if (is_string($amount) && 1 === preg_match('/^-?\d+$/', $amount)) {
            $amount = (int) $amount;
        }

        if (!is_int($amount)) {
            throw new LogicException(sprintf(
                $notAnIntegerMessage,
                is_scalar($amount) ? var_export($amount, true) : get_debug_type($amount),
            ));
        }

        if ($amount <= 0) {
            throw new LogicException(sprintf($notPositiveMessage, $amount));
        }

        return $amount;
    }

    /**
     * Consume the override, so the next operation on the same payment is not silently partial too.
     *
     * Call this only after the operation succeeded. The details are commonly persisted with the
     * payment, so a leftover key outlives the operation it was meant for — but if the call failed, the
     * instruction was never carried out and must survive for a retry.
     *
     * Note the flip side: a *successful* partial operation that is somehow executed a second time
     * against the reloaded payment will fall back to the full `amount`. Set the key again for each
     * partial operation rather than relying on what a previous one left behind.
     *
     * @param ArrayAccess<string, mixed> $details
     */
    public static function consume(ArrayAccess $details, string $overrideKey): void
    {
        if ($details->offsetExists($overrideKey)) {
            $details->offsetUnset($overrideKey);
        }
    }
}
