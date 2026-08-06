<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Examples\E2E;

use Payum\Core\Bridge\PlainPhp\Action\GetHttpRequestAction;
use Payum\Core\Request\GetHttpRequest;

/**
 * A `GetHttpRequest` action for plain PHP that also populates `headers`.
 *
 * This exists because of a real constraint, not for convenience: `NotifyAction` reads the
 * `QuickPay-Checksum-Sha256` header off `GetHttpRequest::$headers` to verify the callback signature,
 * and among payum/core's bridges only the Symfony one sets that property. The plain-PHP bridge leaves
 * it unset, so every callback would be rejected as unsigned with a `400` — see `docs/UPGRADE-2.0.md`.
 * Any non-Symfony consumer needs an action like this one.
 *
 * NOT part of the library. Dev tooling under examples/.
 */
final class HeaderAwareGetHttpRequestAction extends GetHttpRequestAction
{
    /**
     * @param mixed|GetHttpRequest $request
     */
    public function execute($request): void
    {
        // The parent fills method/query/request/clientIp/uri/userAgent and — importantly for the HMAC —
        // reads the raw body into `content` straight from php://input, un-re-encoded.
        parent::execute($request);

        /** @var GetHttpRequest $request */
        $request->headers = self::headers();
    }

    /**
     * @return array<string, string>
     */
    private static function headers(): array
    {
        if (function_exists('getallheaders')) {
            /** @var array<string, string>|false $headers */
            $headers = getallheaders();

            if (is_array($headers)) {
                return $headers;
            }
        }

        // Fallback for SAPIs without getallheaders(): rebuild from $_SERVER. NotifyAction matches the
        // header name case-insensitively, so the exact casing produced here does not matter.
        $headers = [];

        foreach ($_SERVER as $name => $value) {
            if (!is_string($name) || !str_starts_with($name, 'HTTP_') || !is_scalar($value)) {
                continue;
            }

            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
            $headers[$name] = (string) $value;
        }

        return $headers;
    }
}
