<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Model;

class QuickPayPaymentOperation extends QuickPayModel
{
    public const TYPE_AUTHORIZE = 'authorize';
    public const TYPE_CAPTURE = 'capture';
    public const TYPE_REFUND = 'refund';
    public const TYPE_CANCEL = 'cancel';

    public const STATUS_CODE_APPROVED = 20000;

    /**
     * @var int
     */
    protected $id;

    /**
     * @var string
     */
    protected $type;

    /**
     * @var int
     */
    protected $amount;

    /**
     * @var string
     */
    protected $qp_status_code;

    /**
     * @param \stdClass $operations
     *
     * @return QuickPayPaymentOperation
     */
    public static function createFromObject(\stdClass $operations): self
    {
        return new self($operations);
    }

    /**
     * @param array $operations
     *
     * @return array
     */
    public static function createFromArray(array $operations): array
    {
        $ret = [];
        foreach ($operations as $operation) {
            $ret[] = self::createFromObject($operation);
        }

        return $ret;
    }

    /**
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return int
     */
    public function getAmount(): int
    {
        return $this->amount;
    }

    /**
     * @return int
     */
    public function getStatusCode(): int
    {
        return (int) $this->qp_status_code;
    }

    /**
     * @return bool
     */
    public function isApproved(): bool
    {
        return self::STATUS_CODE_APPROVED === $this->getStatusCode();
    }
}
