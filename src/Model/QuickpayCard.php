<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Model;

class QuickpayCard extends QuickPayModel
{
    protected int $number;

    protected string $expiration;

    protected int $cvd;

    public static function createFromArray(array $data): self
    {
        return new self((object) $data);
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    /**
     * Returns the expiration formatted as YYMM.
     */
    public function getExpiration(): string
    {
        return $this->expiration;
    }

    public function getCvd(): int
    {
        return $this->cvd;
    }

    public function toArray(): array
    {
        return [
            'number' => $this->getNumber(),
            'expiration' => $this->getExpiration(),
            'cvd' => $this->getCvd(),
        ];
    }

    public function setNumber(int $number): void
    {
        $this->number = $number;
    }
}
