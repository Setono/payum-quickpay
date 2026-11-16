<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Model;

use Setono\Quickpay\Request\Payment\Address;
use Setono\Quickpay\Request\Payment\BasketItem;
use Setono\Quickpay\Request\Payment\Shipping;
use Setono\Quickpay\Request\Payment\Shopsystem;

/**
 * What Quickpay is told about the order when the gateway creates its payment: the addresses, the
 * basket, shipping and the shopsystem, as the SDK's own request objects. Quickpay shows them on the
 * payment in its manager. The description is informational: it never changes the amount, the currency
 * or the order id, which come from the Payum payment and the gateway options.
 *
 * Not to be confused with Payum's {@see \Payum\Core\Model\PaymentInterface::getDescription()}, a
 * one-line string this gateway does not send.
 *
 * Every part is optional, and a part left out is not sent. A null shopsystem means the gateway names
 * itself (this package and its version).
 */
final class OrderDescription
{
    /**
     * @param list<BasketItem> $basket
     */
    public function __construct(
        public readonly ?Address $invoiceAddress = null,
        public readonly ?Address $shippingAddress = null,
        public readonly array $basket = [],
        public readonly ?Shipping $shipping = null,
        public readonly ?Shopsystem $shopsystem = null,
    ) {
    }
}
