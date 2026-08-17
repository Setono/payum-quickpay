<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Bridge\PlainPhp\Action;

use Payum\Core\Bridge\PlainPhp\Action\GetHttpRequestAction;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Request\GetHttpRequest;

/**
 * A `GetHttpRequest` action for plain-PHP (non-Symfony) setups that also populates `headers`.
 *
 * This exists because of a real constraint, not for convenience:
 * {@see \Setono\Payum\Quickpay\Action\NotifyAction} reads the `QuickPay-Checksum-Sha256` header off
 * `GetHttpRequest::$headers` to verify the callback signature, and among payum/core's bridges only
 * the Symfony one sets that property. The plain-PHP bridge leaves it unset — so on a plain-PHP Payum
 * every callback is rejected as unsigned with a `400`, silently, for every payment. Register this
 * action in its place:
 *
 *     (new PayumBuilder())
 *         ->addCoreGatewayFactoryConfig([
 *             'payum.action.get_http_request' => new HeaderAwareGetHttpRequestAction(),
 *         ])
 *
 * Symfony (and therefore Sylius) consumers need none of this — payum's Symfony bridge populates the
 * headers already.
 *
 * The headers are rebuilt from `$_SERVER` rather than read via `getallheaders()`: that function
 * exists only on some SAPIs, and the polyfills that fill the gap are old and pass `$_SERVER` values
 * through unsanitized. `$_SERVER` is available everywhere and carries every request header under
 * the CGI `HTTP_*` convention, so it is the one source that behaves the same on every SAPI.
 */
final class HeaderAwareGetHttpRequestAction extends GetHttpRequestAction
{
    /**
     * @param mixed|GetHttpRequest $request
     */
    public function execute($request): void
    {
        if (!$request instanceof GetHttpRequest) {
            throw RequestNotSupportedException::createActionNotSupported($this, $request);
        }

        // The parent fills method/query/request/clientIp/uri/userAgent and — importantly for the HMAC —
        // reads the raw body into `content` straight from php://input, un-re-encoded.
        parent::execute($request);

        $request->headers = self::headers();
    }

    /**
     * Rebuilds the request headers from `$_SERVER`. Every request header arrives as `HTTP_*` under
     * the CGI convention; the two entity headers the CGI spec strips the prefix from
     * (`CONTENT_TYPE`, `CONTENT_LENGTH`) are mapped too so the result reads like a full header set.
     * Non-scalar values are dropped. NotifyAction matches the header name case-insensitively, so the
     * exact casing produced here does not matter.
     *
     * @return array<string, string>
     */
    private static function headers(): array
    {
        $headers = [];

        foreach ($_SERVER as $name => $value) {
            if (!is_string($name) || !is_scalar($value)) {
                continue;
            }

            if (str_starts_with($name, 'HTTP_')) {
                $name = substr($name, 5);
            } elseif ('CONTENT_TYPE' !== $name && 'CONTENT_LENGTH' !== $name) {
                continue;
            }

            $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $name))))] = (string) $value;
        }

        return $headers;
    }
}
