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
 *   POST /notify   the Quickpay callback — verified and handled by NotifyAction through the gateway
 *   GET  /done     where the customer lands after the payment window; shows the Payum status
 *   GET  /         recent callbacks (tail of var/callbacks.log)
 *
 * Note this goes through the real gateway: `Notify` runs NotifyAction, which verifies the
 * `QuickPay-Checksum-Sha256` HMAC and, when `auto_capture` is on, captures via ConfirmPaymentAction.
 */

use Payum\Core\Reply\HttpResponse;
use Payum\Core\Request\GetHumanStatus;
use Payum\Core\Request\Notify;

require __DIR__ . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), \PHP_URL_PATH) ?: '/';

// Behind the tunnel the public host is what tokens were minted on; fall back to the request host.
$base = e2e_env('QUICKPAY_CALLBACK_BASE', false);
if ('' === $base) {
    $base = 'https://' . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

if ('POST' === $method && '/notify' === $path) {
    $payum = e2e_payum($base);

    // Two shapes of callback arrive here:
    //  - the payment-window callback, sent to the per-payment notify token url AuthorizeAction built,
    //    so it carries ?payum_token and Payum resolves the model from it;
    //  - a callback sent to the account-wide url (Settings → Integration), which is one static url for
    //    every payment and therefore cannot carry a token — resolve those by the order_id in the body.
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
            'Payum reports <strong>%s</strong>. The browser redirect carries no payment data — only the '
            . 'verified callback (and this status, which re-fetches from Quickpay) is trustworthy.',
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
