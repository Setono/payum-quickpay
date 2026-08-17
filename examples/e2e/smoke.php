<?php

declare(strict_types=1);

/**
 * Quick REAL-API check of the gateway wiring — no browser, no tunnel, charges nothing.
 *
 *   php examples/e2e/smoke.php [amount=1000] [currency=DKK] [--base=https://...]
 *
 * Drives Payum exactly as a shop would: build the gateway, execute `Authorize` against a token, and
 * let the actions do the rest. That single call exercises `ConvertPaymentAction` (which creates the
 * real Quickpay payment) and `AuthorizeAction` → `CreatePaymentLinkAction` (which create the real
 * payment link and throw `HttpRedirect`), then `GetHumanStatus` runs `StatusAction` over the freshly
 * fetched payment.
 *
 * No card is entered, so nothing is charged and no callback is fired. Without --base the token urls
 * are built on a placeholder host — fine here precisely because no callback will arrive.
 */

use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Request\Authorize;
use Payum\Core\Request\GetHumanStatus;
use Setono\Quickpay\Exception\QuickpayException;

require __DIR__ . '/bootstrap.php';

$positional = array_values(array_filter(
    array_slice($argv, 1),
    static fn (string $arg): bool => !str_starts_with($arg, '--'),
));

$amount = isset($positional[0]) ? (int) $positional[0] : 1000;
$currency = $positional[1] ?? 'DKK';

$payum = e2e_payum(e2e_base_url($argv, allowPlaceholder: true));
$gateway = $payum->getGateway('quickpay');

$payment = e2e_create_payment($payum, $amount, $currency);
$token = $payum->getTokenFactory()->createAuthorizeToken('quickpay', $payment, 'done');

$report = static function (string $label, string $result): void {
    fwrite(STDOUT, sprintf("%-9s ... %s\n", $label, $result));
};

$report('payment', sprintf('created number=%s amount=%d %s', $payment->getNumber(), $amount, $currency));

// Authorize: Convert creates the Quickpay payment, AuthorizeAction has the link created and redirects.
$windowUrl = null;

try {
    $gateway->execute(new Authorize($token));
    $report('authorize', 'unexpected: no HttpRedirect reply was thrown');
} catch (HttpRedirect $reply) {
    $windowUrl = $reply->getUrl();
    $report('authorize', 'ok, payment window url returned');
} catch (QuickpayException $e) {
    e2e_fail(sprintf("authorize ... FAILED: %s\n\nThe API rejected the request — check QUICKPAY_API_KEY.", $e->getMessage()));
}

// Status: re-fetches the payment from Quickpay and maps its state onto a Payum mark.
try {
    $gateway->execute($status = new GetHumanStatus($token));
    $report('status', $status->getValue());
} catch (QuickpayException $e) {
    $report('status', 'FAILED: ' . $e->getMessage());
}

/** @var array<string, mixed> $details */
$details = $payment->getDetails();

fwrite(STDOUT, sprintf(
    <<<TXT

    Details stored on the Payum payment (scalars only):
    %s

    Payment window (open to pay with a test card):
      %s

    Nothing was charged. For the full flow — including the signed callback — run the listener and
    the tunnel, then: composer e2e:create -- %d %s

    TXT,
    e2e_format_details($details),
    $windowUrl ?? '(none)',
    $amount,
    $currency,
));
