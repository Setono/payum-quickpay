<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay;

use ArrayAccess;
use Payum\Core\Exception\LogicException;

/**
 * Reads the Quickpay payment id off the details array.
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
