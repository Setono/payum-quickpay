<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay;

use ArrayAccess;
use Payum\Core\Exception\LogicException;

/**
 * Reads the Quickpay-side facts off the details array: the payment id, and the payment's own callback url.
 *
 * `quickpayPaymentId` is the single source of truth for "this payment exists at Quickpay" — the
 * actions re-fetch the payment by it rather than trusting stored snapshots. Reading it through this
 * helper turns the missing-id case into one clear exception at the call site; without it,
 * `(int) $details['quickpayPaymentId']` on a missing key is `(int) null = 0`, which reaches the API
 * as `GET /payments/0` and comes back as a NotFoundException after a network round trip, blaming a
 * payment id nobody ever set.
 */
final class Details
{
    private function __construct()
    {
    }

    /**
     * Whether the details carry a `quickpayPaymentId` at all — whether, as far as the details know, the
     * payment exists at Quickpay. `null` counts as absent: it is what a consumer's own Convert action or
     * a storage round trip may leave behind for "not created yet", and it is how
     * {@see \Setono\Payum\Quickpay\Action\ConvertPaymentAction} reads it (`isset()`), so a `GetStatus` on
     * such a model must answer `new` rather than throw. Payum's `ArrayObject::offsetExists()` alone is
     * `true` for a null value, which is why this exists. Whether a present id is *usable* is
     * {@see self::paymentId()}'s question, and it throws for one that is not.
     *
     * @param ArrayAccess<string, mixed> $details
     */
    public static function hasPaymentId(ArrayAccess $details): bool
    {
        return $details->offsetExists('quickpayPaymentId') && null !== $details['quickpayPaymentId'];
    }

    /**
     * The url an operation issued by the gateway reports back to — the payment's own notify (callback)
     * url, minted when its payment link was created — or null if the payment never went through the
     * window here, in which case Quickpay's default applies.
     *
     * Quickpay sends the callback of an API-issued capture/refund/cancel to the ACCOUNT-WIDE callback
     * url — empty by default — not to the url on the payment link, unless the request names one
     * (`QuickPay-Callback-Url`). Naming this url routes an operation's callback to the same per-payment
     * endpoint the payment window's callback took, so a shop hears about its captures, refunds and
     * cancels without configuring anything at Quickpay.
     *
     * @param ArrayAccess<string, mixed> $details
     */
    public static function callbackUrl(ArrayAccess $details): ?string
    {
        $url = $details->offsetExists('callback_url') ? $details['callback_url'] : null;

        return is_string($url) && '' !== $url ? $url : null;
    }

    /**
     * @param ArrayAccess<string, mixed> $details
     *
     * @throws LogicException if the details carry no usable Quickpay payment id
     */
    public static function paymentId(ArrayAccess $details): int
    {
        $id = $details->offsetExists('quickpayPaymentId') ? $details['quickpayPaymentId'] : null;

        if (is_string($id) && ctype_digit($id)) {
            $id = (int) $id;
        }

        if (!is_int($id) || $id <= 0) {
            throw new LogicException(sprintf(
                'The payment has not been created at Quickpay: the details carry no usable "quickpayPaymentId" (got %s). Execute a Convert request first.',
                get_debug_type($id),
            ));
        }

        return $id;
    }
}
