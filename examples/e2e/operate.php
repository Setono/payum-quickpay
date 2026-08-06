<?php

declare(strict_types=1);

/**
 * Drive the rest of the payment lifecycle through Payum, against a payment created earlier.
 *
 *   php examples/e2e/operate.php status  <payumNumber>
 *   php examples/e2e/operate.php capture <payumNumber> [amount]
 *   php examples/e2e/operate.php refund  <payumNumber> [amount]
 *   php examples/e2e/operate.php cancel  <payumNumber>
 *
 * Passing an amount performs a PARTIAL capture or refund, which the gateway expresses through the
 * `capture_amount` / `refund_amount` details keys (Payum's requests carry no amount of their own).
 *
 * `<payumNumber>` is the number printed by e2e:create — the Payum payment, not the Quickpay id. Each
 * command executes the corresponding Payum request against the real gateway, so this exercises
 * CaptureAction / RefundAction / CancelAction / StatusAction and the real API.
 *
 * Operations are asynchronous unless the gateway is configured with `synchronized` (set
 * QUICKPAY_SYNCHRONIZED=1), so `status` right after a capture may still show the pre-operation state
 * — the settled state arrives in the callback. Run `status` again a moment later.
 */

use Payum\Core\Request\Cancel;
use Payum\Core\Request\Capture;
use Payum\Core\Request\GetHumanStatus;
use Payum\Core\Request\Refund;
use Setono\Quickpay\Exception\QuickpayException;

require __DIR__ . '/bootstrap.php';

$positional = array_values(array_filter(
    array_slice($argv, 1),
    static fn (string $arg): bool => !str_starts_with($arg, '--'),
));

$command = $positional[0] ?? '';
$number = $positional[1] ?? '';

if ('' === $command || '' === $number) {
    e2e_fail(
        "Usage: php examples/e2e/operate.php <status|capture|refund|cancel> <payumNumber> [amount]\n"
        . 'The number is the "payum number" printed by e2e:create.',
    );
}

// A base url is only needed to mint new tokens; these commands act on the stored payment directly.
$payum = e2e_payum(e2e_base_url($argv, allowPlaceholder: true));
$gateway = $payum->getGateway('quickpay');
$payment = e2e_find_payment($payum, $number);

/** @var array<string, mixed> $before */
$before = $payment->getDetails();

$showStatus = static function () use ($gateway, $payment): void {
    $gateway->execute($status = new GetHumanStatus($payment));
    fwrite(STDOUT, sprintf("status    ... %s\n", $status->getValue()));
};

try {
    switch ($command) {
        case 'status':
            $showStatus();

            break;

        case 'capture':
            $amount = e2e_partial_amount($payment, $positional, 'capture_amount');

            try {
                $gateway->execute(new Capture($payment));
                fwrite(STDOUT, sprintf("capture   ... requested %s\n", null === $amount ? '(full amount)' : (string) $amount));
            } finally {
                $payment->setDetails($before);
            }

            $showStatus();

            break;

        case 'refund':
            $amount = e2e_partial_amount($payment, $positional, 'refund_amount');

            try {
                $gateway->execute(new Refund($payment));
                fwrite(STDOUT, sprintf("refund    ... requested %s\n", null === $amount ? '(full amount)' : (string) $amount));
            } finally {
                $payment->setDetails($before);
            }

            $showStatus();

            break;

        case 'cancel':
            // CancelAction swallows Quickpay's "Transaction in wrong state for this operation", so
            // cancelling an already captured or cancelled payment is a no-op rather than an error.
            $gateway->execute(new Cancel($payment));
            fwrite(STDOUT, "cancel    ... requested\n");
            $showStatus();

            break;

        default:
            e2e_fail(sprintf('Unknown command "%s". Use status, capture, refund or cancel.', $command));
    }
} catch (QuickpayException $e) {
    e2e_fail(sprintf("%-9s ... FAILED: %s", $command, $e->getMessage()));
}

$payum->getStorage(get_class($payment))->update($payment);

/** @var array<string, mixed> $details */
$details = $payment->getDetails();

fwrite(STDOUT, sprintf("\nDetails:\n%s\n", e2e_format_details($details)));
