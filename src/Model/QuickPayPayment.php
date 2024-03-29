<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Model;

use JsonException;
use Psr\Http\Message\ResponseInterface;

class QuickPayPayment extends QuickPayModel
{
    public const STATE_INITIAL = 'initial';

    public const STATE_PENDING = 'pending';

    public const STATE_NEW = 'new';

    public const STATE_REJECTED = 'rejected';

    public const STATE_PROCESSED = 'processed';

    protected int $id;

    protected string $currency;

    protected string $order_id;

    protected string $state;

    protected array $operations;

    protected ?int $fee;

    public static function createFromResponse(ResponseInterface $response, string $url = null): self
    {
        $body = (string) $response->getBody();

        try {
            $data = json_decode($body, false, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new JsonException(sprintf(
                'Could not json_decode input. Error was: %s. Called in: %s. Request: %s. Input was: %s',
                $e->getMessage(),
                __METHOD__,
                $url ?? 'Not available',
                $body === '' ? 'Empty' : $body
            ), $e->getCode(), $e);
        }

        return new self($data);
    }

    public static function createFromObject(object $data): self
    {
        return new self($data);
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getOrderId(): string
    {
        return $this->order_id;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    /**
     * @return string initial, pending, new, rejected, processed
     */
    public function getState(): string
    {
        return $this->state;
    }

    public function getFee(): ?int
    {
        return $this->fee;
    }

    public function getAuthorizedAmount(): int
    {
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
        if (\count($this->operations) > 0) {
            return QuickPayPaymentOperation::createFromArray($this->operations);
        }

        return [];
    }

    public function getLatestOperation(): ?QuickPayPaymentOperation
    {
        if (\count($this->operations) > 0) {
            return QuickPayPaymentOperation::createFromObject(end($this->operations));
        }

        return null;
    }
}
