<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay;

use Setono\Quickpay\Callback\CallbackValidator;
use Setono\Quickpay\Client\ClientInterface;
use Setono\Quickpay\Client\Endpoint\PaymentsEndpoint;

/**
 * Immutable value object injected as Payum's `payum.api`. It wraps the configured QuickPay SDK
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
        private readonly bool $synchronized = false,
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

    public function isSynchronized(): bool
    {
        return $this->synchronized;
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
