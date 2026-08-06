<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay;

use Setono\Quickpay\Callback\CallbackValidator;
use Setono\Quickpay\Client\ClientInterface;
use Setono\Quickpay\Client\Endpoint\PaymentsEndpoint;

/**
 * Immutable value object injected as Payum's `payum.api`. It wraps the configured Quickpay SDK
 * client together with the gateway behavior options the actions need.
 *
 * It performs no HTTP itself — the SDK client does (Basic auth, the mandatory `Accept-Version: v10`
 * header, status-code → exception mapping and (de)serialization).
 */
final class Api
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly string $privateKey,
        private readonly string $orderPrefix = '',
        private readonly string $paymentMethods = '',
        private readonly string $language = 'en',
        private readonly bool $autoCapture = false,
        private readonly ?int $agreementId = null,
        private readonly ?int $brandingId = null,
    ) {
    }

    public function getClient(): ClientInterface
    {
        return $this->client;
    }

    public function payments(): PaymentsEndpoint
    {
        return $this->client->payments();
    }

    public function getPrivateKey(): string
    {
        return $this->privateKey;
    }

    public function getOrderPrefix(): string
    {
        return $this->orderPrefix;
    }

    public function getPaymentMethods(): ?string
    {
        return '' !== $this->paymentMethods ? $this->paymentMethods : null;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function isAutoCapture(): bool
    {
        return $this->autoCapture;
    }

    /**
     * Whether the payment operations (capture/refund/cancel) wait for the completed transaction
     * instead of relying on the callback. This lives on the SDK client as its client-wide default —
     * {@see QuickpayGatewayFactory} builds the client from the gateway's `synchronized` option.
     */
    public function isSynchronized(): bool
    {
        return $this->client->isSynchronized();
    }

    public function getAgreementId(): ?int
    {
        return $this->agreementId;
    }

    public function getBrandingId(): ?int
    {
        return $this->brandingId;
    }

    public function createCallbackValidator(): CallbackValidator
    {
        return new CallbackValidator($this->privateKey);
    }
}
