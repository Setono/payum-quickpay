<?php

declare(strict_types=1);

/**
 * Shared bootstrap for the end-to-end harness. Wires a real, framework-less Payum around this gateway
 * and exposes the helpers the CLI scripts and the listener share.
 *
 * NOT part of the library — this is dev tooling under examples/, deliberately outside the phpstan/ecs
 * paths (`src` + `tests`). Check it with `php -l`.
 */

use Payum\Core\Bridge\PlainPhp\Security\TokenFactory;
use Payum\Core\GatewayFactoryInterface;
use Payum\Core\Model\Payment;
use Payum\Core\Model\Token;
use Payum\Core\Payum;
use Payum\Core\PayumBuilder;
use Payum\Core\Registry\StorageRegistryInterface;
use Payum\Core\Storage\FilesystemStorage;
use Payum\Core\Storage\StorageInterface;
use Setono\Payum\Quickpay\Examples\E2E\HeaderAwareGetHttpRequestAction;
use Setono\Payum\Quickpay\QuickpayGatewayFactory;

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/HeaderAwareGetHttpRequestAction.php';

e2e_load_dotenv();

/**
 * Load KEY=VALUE pairs from a `.env.local` file at the repo root into the environment (without
 * overriding variables already set for real). Lets you keep secrets in one local, gitignored file
 * instead of exporting them every time. The function is hoisted, so calling it above is fine.
 */
function e2e_load_dotenv(): void
{
    $path = __DIR__ . '/../../.env.local';
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
    if (false === $lines) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ('' === $line || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);

        if (strlen($value) >= 2
            && (('"' === $value[0] && '"' === $value[-1]) || ("'" === $value[0] && "'" === $value[-1]))) {
            $value = substr($value, 1, -1);
        }

        // Never override a variable already set in the real environment.
        if ('' === $name || false !== getenv($name)) {
            continue;
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

/**
 * Read an environment variable. When required and missing, print guidance and exit.
 */
function e2e_env(string $name, bool $required = true): string
{
    $value = getenv($name);
    if (!is_string($value) || '' === $value) {
        if ($required) {
            fwrite(STDERR, sprintf("Missing required environment variable: %s\n\n", $name));
            fwrite(STDERR, "The harness needs:\n");
            fwrite(STDERR, "  QUICKPAY_API_KEY      your API key       (Quickpay manager > Settings > API user)\n");
            fwrite(STDERR, "  QUICKPAY_PRIVATE_KEY  your private key   (Quickpay manager > Settings > Integration)\n");
            fwrite(STDERR, "Put them in a gitignored .env.local (see .env.local.example) or export them.\n");
            exit(1);
        }

        return '';
    }

    return $value;
}

function e2e_var_dir(): string
{
    $dir = __DIR__ . '/var';
    if (!is_dir($dir)) {
        mkdir($dir, 0o775, true);
    }

    return $dir;
}

function e2e_log_path(): string
{
    return e2e_var_dir() . '/callbacks.log';
}

/**
 * Log a line to the terminal and append it to var/callbacks.log (shown on the listener's index page).
 */
function e2e_log(string $line): void
{
    $stamped = sprintf('[%s] %s', date('Y-m-d H:i:s'), $line);

    if (defined('STDERR')) {
        fwrite(STDERR, $stamped . "\n");
    } else {
        // The built-in server SAPI (cli-server) does not define STDERR; error_log reaches the terminal.
        error_log($stamped);
    }

    @file_put_contents(e2e_log_path(), $stamped . "\n", FILE_APPEND);
}

/**
 * Resolve the public base URL that Payum builds its token URLs on — the same URL the listener is
 * reachable at from the internet, because Quickpay POSTs the callback to a token URL under it.
 *
 * Comes from `--base=` or QUICKPAY_CALLBACK_BASE. `$allowPlaceholder` is for the scripts that never
 * need a callback to actually arrive (smoke), which fall back to a syntactically valid dummy.
 *
 * @param list<string> $argv
 */
function e2e_base_url(array $argv, bool $allowPlaceholder = false): string
{
    $base = '';
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--base=')) {
            $base = substr($arg, strlen('--base='));
        }
    }

    if ('' === $base) {
        $base = e2e_env('QUICKPAY_CALLBACK_BASE', false);
    }

    if ('' === $base && $allowPlaceholder) {
        return 'https://example.com';
    }

    if ('' === $base) {
        fwrite(STDERR, "Missing the public base URL.\n");
        fwrite(STDERR, "Pass --base=https://xxx.sharedwithexpose.com or set QUICKPAY_CALLBACK_BASE.\n");
        fwrite(STDERR, "This is the https URL printed by `expose share` (it changes every session on the free tier).\n");
        exit(1);
    }

    $base = rtrim($base, '/');

    if (!str_starts_with($base, 'https://')) {
        fwrite(STDERR, sprintf("The base URL must be https:// (Quickpay requires TLS for callbacks). Got: %s\n", $base));
        exit(1);
    }

    return $base;
}

/**
 * Build a real Payum around this gateway — the same wiring a consumer would do, minus a framework.
 *
 * Storage is on the filesystem under examples/e2e/var/payum so the CLI scripts and the web listener
 * (separate processes) see the same payments and tokens.
 */
function e2e_payum(string $baseUrl): Payum
{
    $storageDir = e2e_var_dir() . '/payum';
    if (!is_dir($storageDir)) {
        mkdir($storageDir, 0o775, true);
    }

    return (new PayumBuilder())
        ->setTokenStorage(new FilesystemStorage($storageDir, Token::class, 'hash'))
        ->addStorage(Payment::class, new FilesystemStorage($storageDir, Payment::class, 'number'))
        // Token urls are absolute and built on the public base url, so the ones Quickpay receives
        // (callback_url, continue_url, cancel_url) point back through the tunnel at our listener.
        ->setTokenFactory(static function (StorageInterface $tokenStorage, StorageRegistryInterface $registry) use ($baseUrl): TokenFactory {
            return new TokenFactory($tokenStorage, $registry, $baseUrl);
        })
        ->setGenericTokenFactoryPaths([
            'capture' => 'capture',
            'authorize' => 'authorize',
            'notify' => 'notify',
            'refund' => 'refund',
            'cancel' => 'cancel',
            'done' => 'done',
        ])
        // Without this the callback verification can never pass outside Symfony — see the class docblock.
        ->addCoreGatewayFactoryConfig([
            'payum.action.get_http_request' => new HeaderAwareGetHttpRequestAction(),
        ])
        ->addGatewayFactory('quickpay', static function (array $config, GatewayFactoryInterface $coreGatewayFactory): QuickpayGatewayFactory {
            return new QuickpayGatewayFactory($config, $coreGatewayFactory);
        })
        ->addGateway('quickpay', [
            'factory' => 'quickpay',
            'api_key' => e2e_env('QUICKPAY_API_KEY'),
            'private_key' => e2e_env('QUICKPAY_PRIVATE_KEY'),
            'order_prefix' => e2e_env('QUICKPAY_ORDER_PREFIX', false),
            'language' => e2e_env('QUICKPAY_LANGUAGE', false) ?: 'en',
            'payment_methods' => e2e_env('QUICKPAY_PAYMENT_METHODS', false),
            'auto_capture' => e2e_bool('QUICKPAY_AUTO_CAPTURE') ? 1 : 0,
            'synchronized' => e2e_bool('QUICKPAY_SYNCHRONIZED'),
            'agreement_id' => e2e_env('QUICKPAY_AGREEMENT', false),
            'branding_id' => e2e_env('QUICKPAY_BRANDING_ID', false),
        ])
        ->getPayum();
}

function e2e_bool(string $name): bool
{
    return filter_var(e2e_env($name, false), \FILTER_VALIDATE_BOOL);
}

/**
 * Create (and persist) a Payum payment model — the thing a shop would own.
 */
function e2e_create_payment(Payum $payum, int $amount, string $currency): Payment
{
    /** @var StorageInterface $storage */
    $storage = $payum->getStorage(Payment::class);

    /** @var Payment $payment */
    $payment = $storage->create();
    // Quickpay's order_id must be 4-20 characters and the gateway builds it as order_prefix + number,
    // so keep the number short enough to leave room for a prefix (14 here allows up to 6).
    $payment->setNumber(date('ymdHis') . bin2hex(random_bytes(1)));
    $payment->setTotalAmount($amount);
    $payment->setCurrencyCode($currency);
    $payment->setDescription('payum-quickpay e2e');

    $storage->update($payment);

    return $payment;
}

/**
 * Resolve the Payum payment a callback refers to, from the callback body alone.
 *
 * Needed for callbacks sent to the **account-wide** callback url (Quickpay manager → Settings →
 * Integration). That is one static url for every payment, so unlike the per-payment notify token url
 * the gateway builds for the payment window, it cannot carry a `payum_token`. `NotifyAction` itself
 * never needs the token — only the model — so matching on `order_id` is enough here.
 */
function e2e_payment_from_callback_body(Payum $payum, string $rawBody): ?Payment
{
    /** @var mixed $data */
    $data = json_decode($rawBody, true);

    if (!is_array($data) || !isset($data['order_id']) || !is_string($data['order_id'])) {
        return null;
    }

    // The gateway builds order_id as order_prefix . number, so strip the prefix back off.
    $prefix = e2e_env('QUICKPAY_ORDER_PREFIX', false);
    $number = '' !== $prefix && str_starts_with($data['order_id'], $prefix)
        ? substr($data['order_id'], strlen($prefix))
        : $data['order_id'];

    /** @var StorageInterface $storage */
    $storage = $payum->getStorage(Payment::class);

    /** @var Payment|null $payment */
    $payment = $storage->find($number);

    return $payment;
}

/**
 * Stage a partial capture/refund by writing the gateway's amount-override key onto the details, when
 * an amount was passed on the command line. Returns the amount, or null for a full-amount operation.
 *
 * The caller restores the original details afterwards — leaving the key behind would silently make the
 * next operation partial too.
 *
 * @param list<string> $positional
 */
function e2e_partial_amount(Payment $payment, array $positional, string $key): ?int
{
    if (!isset($positional[2])) {
        return null;
    }

    $amount = (int) $positional[2];

    $details = $payment->getDetails();
    $details[$key] = $amount;
    $payment->setDetails($details);

    return $amount;
}

/**
 * Load a persisted payment by its number, or exit with guidance.
 */
function e2e_find_payment(Payum $payum, string $number): Payment
{
    /** @var StorageInterface $storage */
    $storage = $payum->getStorage(Payment::class);

    /** @var Payment|null $payment */
    $payment = $storage->find($number);

    if (null === $payment) {
        e2e_fail(sprintf(
            "No payment %s in %s.\nCreate one first: composer e2e:create -- 1000 DKK",
            $number,
            e2e_var_dir() . '/payum',
        ));
    }

    return $payment;
}

/**
 * Render the details array a Payum payment carries, for eyeballing what the gateway stored.
 *
 * @param array<string, mixed> $details
 */
function e2e_format_details(array $details): string
{
    $lines = [];

    foreach ($details as $key => $value) {
        $lines[] = sprintf('  %-20s %s', $key, is_scalar($value) ? (string) $value : gettype($value));
    }

    return [] === $lines ? '  (empty)' : implode("\n", $lines);
}

/**
 * @return never
 */
function e2e_fail(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}
