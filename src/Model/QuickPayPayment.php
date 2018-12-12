<?php

namespace Setono\Payum\QuickPay\Model;

use Psr\Http\Message\ResponseInterface;

/**
 * @author jdk
 */
class QuickPayPayment extends QuickPayModel
{
    const STATE_INITIAL = 'initial';
    const STATE_PENDING = 'pending';
    const STATE_NEW = 'new';
    const STATE_REJECTED = 'rejected';
    const STATE_PROCESSED = 'processed';

    /**
     * @param ResponseInterface $response
     *
     * @return self
     */
    public static function createFromResponse(ResponseInterface $response)
    {
        $data = json_decode($response->getBody());

        return new self($data);
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
    public function getOrderId()
    {
        return $this->data->order_id;
    }

    /**
     * @return string
     */
    public function getCurrency()
    {
        return $this->data->currency;
    }

    /**
     * @return string initial, pending, new, rejected, processed
     */
    public function getState()
    {
        return $this->data->state;
    }

    /**
     * @return int
     */
    public function getAuthorizedAmount() {
        /** @var QuickPayPaymentOperation[] $operations */
        $operations = array_reverse($this->getOperations());
        foreach ($operations as $operation) {
            if ($operation->getType() == QuickPayPaymentOperation::TYPE_AUTHORIZE && $operation->isApproved()) {
                return $operation->getAmount();
            }
        }
        return 0;
    }

    /**
     * @return QuickPayPaymentOperation[]
     */
    public function getOperations()
    {
        if (count($this->data->operations) > 0) {
            return QuickPayPaymentOperation::createFromArray($this->data->operations);
        }
        return [];
    }

    /**
     * @return QuickPayPaymentOperation|null
     */
    public function getLatestOperation()
    {
        if (count($this->data->operations) > 0) {
            return QuickPayPaymentOperation::createFromObject(end($this->data->operations));
        }
        return null;
    }
}
