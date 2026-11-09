<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Exception;

use Payum\Core\Exception\LogicException;

/**
 * A gateway configuration the gateway cannot be built from — thrown by
 * {@see \Setono\Payum\Quickpay\GatewayOptions::fromConfig()}, and so when the gateway is built.
 *
 * It carries every violation, not just the first, so all of them can be fixed (or shown on their form
 * fields) at once. A {@see LogicException} — Payum's, which is PHP's — like the exceptions an invalid
 * configuration threw before, so existing catches keep working.
 */
final class InvalidGatewayOptions extends LogicException
{
    /**
     * @param non-empty-array<string, string> $violations a message per invalid option, keyed by the option's current name
     */
    public function __construct(private readonly array $violations)
    {
        parent::__construct(sprintf('Invalid Quickpay gateway options: %s', implode('; ', $violations)));
    }

    /**
     * @return non-empty-array<string, string>
     */
    public function getViolations(): array
    {
        return $this->violations;
    }
}
