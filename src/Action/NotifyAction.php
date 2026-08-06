<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Action;

use ArrayAccess;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Reply\HttpResponse;
use Payum\Core\Request\GetHttpRequest;
use Payum\Core\Request\Notify;
use Setono\Payum\Quickpay\Action\Api\ApiAwareTrait;
use Setono\Payum\Quickpay\Request\Api\ConfirmPayment;
use Setono\Quickpay\Callback\CallbackValidator;

/**
 * Handles the server-to-server callback (webhook) from Quickpay.
 *
 * The raw request body is verified against the `QuickPay-Checksum-Sha256` HMAC signature (computed
 * with the account private key) before the callback is acted upon, so forged or tampered callbacks
 * are rejected with a 400 response.
 */
class NotifyAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use ApiAwareTrait;
    use GatewayAwareTrait;

    /**
     * @param mixed|Notify $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $model = ArrayObject::ensureArrayObject($request->getModel());

        $httpRequest = new GetHttpRequest();
        $this->gateway->execute($httpRequest);

        $checksum = $this->extractChecksum($httpRequest);

        if ('' === $checksum || !$this->api->createCallbackValidator()->isValid($httpRequest->content, $checksum)) {
            throw new HttpResponse('Invalid checksum', 400);
        }

        $this->gateway->execute(new ConfirmPayment($model));
    }

    public function supports($request): bool
    {
        return $request instanceof Notify && $request->getModel() instanceof ArrayAccess;
    }

    /**
     * Reads the Quickpay checksum header off the `headers` property of the http request. The lookup is
     * case-insensitive because the bridges normalize header casing inconsistently, and the value may be
     * a list or a plain string.
     */
    private function extractChecksum(GetHttpRequest $httpRequest): string
    {
        // `headers` is not declared on GetHttpRequest — it is a dynamic property populated by whichever
        // payum/core GetHttpRequest bridge the consumer wired, and only the Symfony one
        // (Payum\Core\Bridge\Symfony\Action\GetHttpRequestAction) sets it; the PlainPhp bridge does not.
        // Hence the defensive read through get_object_vars(): a bridge that leaves it unset yields no
        // checksum, and execute() rejects the callback with a 400.
        $headers = get_object_vars($httpRequest)['headers'] ?? [];
        if (!is_array($headers)) {
            return '';
        }

        foreach ($headers as $name => $value) {
            if (0 !== strcasecmp((string) $name, CallbackValidator::CHECKSUM_HEADER)) {
                continue;
            }

            if (is_array($value)) {
                $value = reset($value);
            }

            return is_scalar($value) ? (string) $value : '';
        }

        return '';
    }
}
