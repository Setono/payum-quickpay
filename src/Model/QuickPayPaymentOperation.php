<?php

namespace Setono\Payum\QuickPay\Model;

use phpDocumentor\Reflection\Types\Object_;
use Psr\Http\Message\ResponseInterface;

/**
 * @author jdk
 */
class QuickPayPaymentOperation extends QuickPayModel
{
    const TYPE_AUTHORIZE = 'authorize';
    const TYPE_CAPTURE = 'capture';

    const STATUS_CODE_APPROVED = 20000;

    /**
     * @param \stdClass $operations
     *
     * @return QuickPayPaymentOperation
     */
    public static function createFromObject(\stdClass $operations)
    {
        return new self($operations);
    }

    /**
     * @param array $operations
     *
     * @return array
     */
    public static function createFromArray(array $operations) {
        $ret = [];
        foreach ($operations as $operation) {
            $ret[] = self::createFromObject($operation);
        }
        return $ret;
    }

    /**
     * @return int
     */
    public function getId()
    {
        return $this->data->id;
    }

    /**
     * @return string
     */
    public function getType()
    {
        return $this->data->type;
    }

    /**
     * @return int
     */
    public function getAmount()
    {
        return $this->data->amount;
    }

    /**
     * @return int
     */
    public function getStatusCode()
    {
        return $this->data->qp_status_code;
    }

    /**
     * @return bool
     */
    public function isApproved() {
        return $this->getStatusCode() == self::STATUS_CODE_APPROVED;
    }
}
