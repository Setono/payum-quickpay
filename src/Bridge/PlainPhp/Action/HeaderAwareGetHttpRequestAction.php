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
 */
final class HeaderAwareGetHttpRequestAction extends GetHttpRequestAction
{
    /** @var callable(): array<mixed> */
    private $headerSource;

    /**
     * @param (callable(): array<mixed>)|null $headerSource where the raw headers come from. Defaults to
     *                                                     {@see self::defaultHeaderSource()}: the SAPI's
     *                                                     getallheaders() when it has one, otherwise a
     *                                                     reconstruction from `$_SERVER`. Injectable so the
     *                                                     reconstruction can be exercised deterministically —
     *                                                     under PHPUnit a getallheaders() polyfill is usually
     *                                                     loaded (guzzle ships one), which would otherwise leave
     *                                                     the fallback path untested.
     */
    public function __construct(?callable $headerSource = null)
    {
        $this->headerSource = $headerSource ?? self::defaultHeaderSource(...);
    }

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

        $request->headers = $this->headers();
    }

    /**
     * Whichever source provided the headers, the result is sanitized the same way: string names,
     * scalar values stringified, everything else dropped. getallheaders() may be the SAPI's own or a
     * polyfill (guzzle ships one), and polyfills pass `$_SERVER` values through untouched — so the
     * shape cannot be trusted source by source.
     *
     * @return array<string, string>
     */
    private function headers(): array
    {
        $headers = [];

        foreach (($this->headerSource)() as $name => $value) {
            if (!is_string($name) || !is_scalar($value)) {
                continue;
            }

            $headers[$name] = (string) $value;
        }

        return $headers;
    }

    /**
     * The default source: getallheaders() when the SAPI (or a polyfill) provides one and it returns
     * something, otherwise {@see self::headersFromServer()}.
     *
     * @return array<mixed>
     */
    public static function defaultHeaderSource(): array
    {
        // One decision, not two: "did the SAPI hand us headers?" A missing getallheaders() and one
        // that answers with an empty list (some SAPIs define it but return nothing useful) are the
        // same situation from here, and the $_SERVER reconstruction serves both.
        $headers = function_exists('getallheaders') ? getallheaders() : [];

        return [] !== $headers ? $headers : self::headersFromServer();
    }

    /**
     * Rebuilds the request headers from `$_SERVER`'s `HTTP_*` entries — the fallback for SAPIs
     * without getallheaders(). NotifyAction matches the header name case-insensitively, so the exact
     * casing produced here does not matter.
     *
     * @return array<string, mixed>
     */
    public static function headersFromServer(): array
    {
        $headers = [];

        foreach ($_SERVER as $name => $value) {
            if (!is_string($name) || !str_starts_with($name, 'HTTP_')) {
                continue;
            }

            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
            $headers[$name] = $value;
        }

        return $headers;
    }
}
