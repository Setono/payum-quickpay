<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Model;

use Payum\Core\Model\Payment;

/**
 * Payum's payment model with an {@see OrderDescription}, for an integration that has no payment model
 * of its own to implement {@see DescribedPaymentInterface} on. The description is empty until one is
 * set, and an empty description sends nothing.
 */
class DescribedPayment extends Payment implements DescribedPaymentInterface
{
    protected OrderDescription $orderDescription;

    public function __construct()
    {
        parent::__construct();

        $this->orderDescription = new OrderDescription();
    }

    public function getOrderDescription(): OrderDescription
    {
        return $this->orderDescription;
    }

    public function setOrderDescription(OrderDescription $orderDescription): void
    {
        $this->orderDescription = $orderDescription;
    }
}
