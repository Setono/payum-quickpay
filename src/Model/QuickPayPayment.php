<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Model;

use Psr\Http\Message\ResponseInterface;

class QuickPayPayment extends QuickPayModel
{
    public const STATE_INITIAL = 'initial';
    public const STATE_PENDING = 'pending';
    public const STATE_NEW = 'new';
    public const STATE_REJECTED = 'rejected';
    public const STATE_PROCESSED = 'processed';

    /**
     * @param ResponseInterface $response
     *
     * @return QuickPayPayment
     */
    public static function createFromResponse(ResponseInterface $response): self
    {
        $data = json_decode((string) $response->getBody());

        return new self($data);
    }

    /**
     * @param object $data
     *
     * @return QuickPayPayment
     */
    public static function createFromObject($data): self
    {
        return new self($data);
    }

    /**
     * @return int
     */
    public function getId(): int
    {
        return $this->data->id;
    }

    /**
     * @return string
     */
    public function getOrderId(): string
    {
        return $this->data->order_id;
    }

    /**
     * @return string
     */
    public function getCurrency(): string
    {
        return $this->data->currency;
    }

    /**
     * @return string initial, pending, new, rejected, processed
     */
    public function getState(): string
    {
        return $this->data->state;
    }

    /**
     * @return int
     */
    public function getAuthorizedAmount(): int
    {
        /** @var QuickPayPaymentOperation[] $operations */
        $operations = array_reverse($this->getOperations());

        foreach ($operations as $operation) {
            if (QuickPayPaymentOperation::TYPE_AUTHORIZE === $operation->getType() && $operation->isApproved()) {
                return $operation->getAmount();
            }
        }

        return 0;
    }

    /**
     * @return QuickPayPaymentOperation[]
     */
    public function getOperations(): array
    {
        if (count($this->data->operations) > 0) {
            return QuickPayPaymentOperation::createFromArray($this->data->operations);
        }

        return [];
    }

    /**
     * @return QuickPayPaymentOperation|null
     */
    public function getLatestOperation(): ?QuickPayPaymentOperation
    {
        if (count($this->data->operations) > 0) {
            return QuickPayPaymentOperation::createFromObject(end($this->data->operations));
        }

        return null;
    }
}
