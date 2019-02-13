<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Model;

class QuickpayCard extends QuickPayModel
{
    /**
     * @param array $data
     *
     * @return QuickpayCard
     */
    public static function createFromArray(array $data): self
    {
        return new self((object) $data);
    }

    /**
     * @return int
     */
    public function getNumber(): int
    {
        return (int) $this->data->number;
    }

    /**
     * @return string YYMM
     */
    public function getExpiration(): string
    {
        return $this->data->expiration;
    }

    /**
     * @return int
     */
    public function getCvd(): int
    {
        return (int) $this->data->cvd;
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        return [
            'number' => $this->getNumber(),
            'expiration' => $this->getExpiration(),
            'cvd' => $this->getCvd(),
        ];
    }

    /**
     * @param int $number
     */
    public function setNumber(int $number): void
    {
        $this->data->number = $number;
    }
}
