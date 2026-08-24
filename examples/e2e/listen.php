<?php

declare(strict_types=1);

/**
 * Callback listener for the end-to-end harness.
 *
 * Run it as the router for PHP's built-in server, bound to 0.0.0.0 so a tunnel container can reach it:
 *
 *   php -S 0.0.0.0:8000 examples/e2e/listen.php
 *
 * Routes (paths come from the Payum token urls built in bootstrap.php):
 *   GET  /capture    the capture token url — Payum's capture controller, framework-less: it executes
 *                    Capture(token) and either redirects to the payment window (first pass) or, on
 *                    the RETURN TRIP from Quickpay (continue_url points here), lets CaptureAction find
 *                    the payment captured, invalidates the token and redirects to the after url
 *   GET  /authorize  the same for Authorize(token)
 *   POST /notify     the Quickpay callback — verified and handled by NotifyAction through the gateway
 *   GET  /done       the after url: where the customer lands once the token url has run; shows status
 *   GET  /           recent callbacks (tail of var/callbacks.log)
 *
 * Note this goes through the real gateway: `Notify` runs NotifyAction, which verifies the
 * `QuickPay-Checksum-Sha256` HMAC and refreshes the details via ConfirmPaymentAction. Capturing on
 * authorization is the payment link's own auto_capture flag (set by CaptureAction), never the callback.
 */

use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Reply\HttpResponse;
use Payum\Core\Reply\ReplyInterface;
use Payum\Core\Request\Authorize;
use Payum\Core\Request\Capture;
use Payum\Core\Request\GetHumanStatus;
use Payum\Core\Request\Notify;

require __DIR__ . '/bootstrap.php';

// payum/core's plain-PHP TokenFactory and RequestTokenVerifier call league/uri 7 methods that are
// deprecated in that version, and the built-in server prints deprecations into the response body —
// which turned the /done page into a wall of notices during the live check. Not our code, not
// actionable here; hide only that class of notice so anything real still shows.
error_reporting(\E_ALL & ~\E_DEPRECATED & ~\E_USER_DEPRECATED);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), \PHP_URL_PATH) ?: '/';

// Behind the tunnel the public host is what tokens were minted on; fall back to the request host.
$base = e2e_env('QUICKPAY_CALLBACK_BASE', false);
if ('' === $base) {
    $base = 'https://' . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

// The token urls: Payum's capture / authorize controllers, framework-less. Both passes of an
// interactive flow land here — the first when the shop starts the checkout (create-payment.php does
// that from the CLI instead, so this is mostly for the RETURN TRIP: continue_url is this very url,
// with the payum_token, so the action that sent the customer out runs again and finds the outcome).
if ('GET' === $method && in_array($path, ['/capture', '/authorize'], true)) {
    $payum = e2e_payum($base);

    try {
        $token = $payum->getHttpRequestVerifier()->verify($_REQUEST);
    } catch (Throwable $e) {
        http_response_code(400);
        e2e_log(sprintf('TOKEN URL REJECTED %s: %s', $path, $e->getMessage()));
        echo e2e_page('Invalid token', htmlspecialchars($e->getMessage(), \ENT_QUOTES));

        return;
    }

    $gateway = $payum->getGateway($token->getGatewayName());
    $request = '/capture' === $path ? new Capture($token) : new Authorize($token);

    try {
        $gateway->execute($request);
    } catch (HttpRedirect $reply) {
        // First pass: off to the payment window. The token stays valid for the return trip.
        e2e_log(sprintf('TOKEN URL %s → redirect to %s', $path, $reply->getUrl()));
        header('Location: ' . $reply->getUrl(), true, 302);

        return;
    } catch (ReplyInterface $reply) {
        http_response_code(500);
        e2e_log(sprintf('TOKEN URL %s: unexpected reply %s', $path, get_class($reply)));
        echo e2e_page('Unexpected reply', htmlspecialchars(get_class($reply), \ENT_QUOTES));

        return;
    } catch (Throwable $e) {
        http_response_code(500);
        e2e_log(sprintf('TOKEN URL %s ERROR: %s: %s', $path, get_class($e), $e->getMessage()));
        echo e2e_page('Error', htmlspecialchars($e->getMessage(), \ENT_QUOTES));

        return;
    }

    // Second pass, the return trip: the action ran to completion (no redirect), so the flow is done —
    // exactly what Payum's controller does next: invalidate the token and send the customer on.
    $payum->getHttpRequestVerifier()->invalidate($token);
    e2e_log(sprintf('TOKEN URL %s → done, on to %s', $path, (string) $token->getAfterUrl()));
    header('Location: ' . (string) $token->getAfterUrl(), true, 302);

    return;
}

if ('POST' === $method && '/notify' === $path) {
    $payum = e2e_payum($base);

    // Two shapes of callback arrive here:
    //  - the per-payment notify token url the gateway minted when it created the link
    //    (CreatePaymentLinkAction, on behalf of Capture or Authorize): the payment window's callback goes
    //    there, and so do the callbacks of the captures/refunds/cancels the gateway issues, because it
    //    names that url on each operation (QuickPay-Callback-Url) — these carry ?payum_token and Payum
    //    resolves the model from it;
    //  - a callback sent to the account-wide url (Settings → Integration) — an operation made in the
    //    manager — which is one static url for every payment and cannot carry a token: resolve those by
    //    the order_id in the body.
    // Either way NotifyAction verifies the HMAC itself; the subject only tells it which model to act on.
    $via = 'token';

    if (isset($_REQUEST['payum_token'])) {
        try {
            $subject = $payum->getHttpRequestVerifier()->verify($_REQUEST);
        } catch (Throwable $e) {
            http_response_code(400);
            e2e_log('CALLBACK REJECTED (token): ' . $e->getMessage());
            echo "invalid token\n";

            return;
        }
    } else {
        $via = 'order_id';
        $subject = e2e_payment_from_callback_body($payum, (string) file_get_contents('php://input'));

        if (null === $subject) {
            http_response_code(404);
            e2e_log('CALLBACK REJECTED (no payum_token, and no stored payment matches the body order_id)');
            echo "unknown payment\n";

            return;
        }
    }

    $gateway = $payum->getGateway('quickpay');

    try {
        $gateway->execute(new Notify($subject));
    } catch (HttpResponse $reply) {
        // NotifyAction rejects an unsigned or tampered callback with a 400 before acting on it.
        http_response_code($reply->getStatusCode());
        e2e_log(sprintf('CALLBACK REJECTED (%d): %s', $reply->getStatusCode(), $reply->getContent()));
        echo $reply->getContent();

        return;
    } catch (Throwable $e) {
        http_response_code(500);
        e2e_log(sprintf('CALLBACK ERROR: %s: %s', get_class($e), $e->getMessage()));
        echo "error\n";

        return;
    }

    // Verified and handled. Report what the gateway made of it.
    $gateway->execute($status = new GetHumanStatus($subject));

    // After execution the request carries the details the actions worked on, not the token.
    $model = $status->getModel();
    /** @var array<string, mixed> $details */
    $details = $model instanceof ArrayAccess ? (array) $model : [];

    e2e_log(sprintf(
        'CALLBACK OK  via=%s payum_status=%s quickpay_id=%s order_id=%s amount=%s',
        $via,
        $status->getValue(),
        is_scalar($details['quickpayPaymentId'] ?? null) ? (string) $details['quickpayPaymentId'] : '-',
        is_scalar($details['order_id'] ?? null) ? (string) $details['order_id'] : '-',
        is_scalar($details['amount'] ?? null) ? (string) $details['amount'] : '-',
    ));

    http_response_code(200);
    echo "ok\n";

    return;
}

if ('GET' === $method && '/done' === $path) {
    try {
        $payum = e2e_payum($base);
        $token = $payum->getHttpRequestVerifier()->verify($_REQUEST);
        $gateway = $payum->getGateway($token->getGatewayName());
        $gateway->execute($status = new GetHumanStatus($token));

        echo e2e_page('Payment ' . $status->getValue(), sprintf(
            'Payum reports <strong>%s</strong>. You got here through the token url (continue_url), where '
            . 'the Capture/Authorize that started the flow ran once more and found its outcome — that, '
            . 'the verified callback, and this status (which re-fetches from Quickpay) are trustworthy; '
            . 'the redirect itself carries no payment data.',
            htmlspecialchars($status->getValue(), \ENT_QUOTES),
        ));
    } catch (Throwable $e) {
        http_response_code(400);
        echo e2e_page('Could not resolve the payment', htmlspecialchars($e->getMessage(), \ENT_QUOTES));
    }

    return;
}

if ('GET' === $method && '/' === $path) {
    $log = is_file(e2e_log_path()) ? (string) file_get_contents(e2e_log_path()) : '';
    $recent = implode("\n", array_slice(array_values(array_filter(explode("\n", $log))), -50));

    header('Content-Type: text/plain; charset=utf-8');
    echo "payum-quickpay e2e callback listener\n\nRecent callbacks (newest last):\n\n" . ('' === $recent ? '(none yet)' : $recent) . "\n";

    return;
}

http_response_code(404);
echo "not found\n";

function e2e_page(string $title, string $body): string
{
    return sprintf(
        '<!doctype html><meta charset="utf-8"><title>%s</title>'
        . '<body style="font-family:system-ui,sans-serif;max-width:40rem;margin:4rem auto;line-height:1.5">'
        . '<h1>%s</h1><p>%s</p></body>',
        htmlspecialchars($title, \ENT_QUOTES),
        htmlspecialchars($title, \ENT_QUOTES),
        $body,
    );
}
