<?php

declare(strict_types=1);

/**
 * Create a real payment through Payum and print the payment-window URL to open in a browser.
 *
 *   QUICKPAY_CALLBACK_BASE=https://xxx.sharedwithexpose.com \
 *     php examples/e2e/create-payment.php [amount=1000] [currency=DKK]
 *
 * The base URL must point at your running listener through the tunnel: Payum builds the notify,
 * continue and cancel token urls on it, and those are the urls Quickpay is given.
 */

use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Request\Authorize;

require __DIR__ . '/bootstrap.php';

$positional = array_values(array_filter(
    array_slice($argv, 1),
    static fn (string $arg): bool => !str_starts_with($arg, '--'),
));

$amount = isset($positional[0]) ? (int) $positional[0] : 1000;
$currency = $positional[1] ?? 'DKK';

$payum = e2e_payum(e2e_base_url($argv));
$gateway = $payum->getGateway('quickpay');

$payment = e2e_create_payment($payum, $amount, $currency);

// The token carries the payment identity and the after-url; ConvertPaymentAction turns the after-url
// into continue_url/cancel_url, and AuthorizeAction mints a separate notify token for callback_url.
$token = $payum->getTokenFactory()->createAuthorizeToken('quickpay', $payment, 'done');

$windowUrl = null;

try {
    $gateway->execute(new Authorize($token));
} catch (HttpRedirect $reply) {
    $windowUrl = $reply->getUrl();
}

if (null === $windowUrl) {
    e2e_fail('Expected an HttpRedirect reply carrying the payment window url, got none.');
}

/** @var array<string, mixed> $details */
$details = $payment->getDetails();

fwrite(STDOUT, sprintf(
    <<<TXT
    Created payment
      payum number:  %s
      quickpay id:   %s
      order_id:      %s
      amount:        %d %s
      callback_url:  %s
      done url:      %s

    Open the payment window in a browser:
      %s

    Pay with a test card (any valid-looking expiry + CVD), e.g.:
      1000 0000 0000 0008   approved
      1000 0000 0000 0016   rejected
      1000 0000 0000 0032   capture rejected
      1000 0000 0000 0073   3-D Secure required

    Then watch the listener terminal for the verified callback, and drive the rest of the
    lifecycle through Payum with:
      composer e2e:operate -- status  %s
      composer e2e:operate -- capture %s
      composer e2e:operate -- refund  %s %d
      composer e2e:operate -- cancel  %s

    TXT,
    $payment->getNumber(),
    is_scalar($details['quickpayPaymentId'] ?? null) ? (string) $details['quickpayPaymentId'] : '(none)',
    is_scalar($details['order_id'] ?? null) ? (string) $details['order_id'] : '(none)',
    $amount,
    $currency,
    is_scalar($details['callback_url'] ?? null) ? (string) $details['callback_url'] : '(none)',
    $token->getAfterUrl() ?? '(none)',
    $windowUrl,
    $payment->getNumber(),
    $payment->getNumber(),
    $payment->getNumber(),
    $amount,
    $payment->getNumber(),
));
