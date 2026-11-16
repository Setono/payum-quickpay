<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Model;

use Payum\Core\Model\PaymentInterface;

/**
 * A Payum payment that describes its order to the gateway. When `Convert` creates the Quickpay
 * payment for it, the {@see OrderDescription} goes with the create. It is read on the create path
 * only: a Quickpay payment that already exists, or one picked up under the same order id, keeps the
 * description it was created with.
 */
interface DescribedPaymentInterface extends PaymentInterface
{
    public function getOrderDescription(): OrderDescription;
}
