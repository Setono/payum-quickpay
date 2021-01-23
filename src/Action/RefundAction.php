<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Action;

use ArrayAccess;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\Refund;
use Setono\Payum\QuickPay\Action\Api\ApiAwareTrait;

class RefundAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use GatewayAwareTrait;
    use ApiAwareTrait;

    /**
     * {@inheritdoc}
     *
     * @param Refund $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $model = ArrayObject::ensureArrayObject($request->getModel());

        $quickpayPayment = $this->api->getPayment($model);

        $this->api->refundPayment($quickpayPayment, $model);
    }

    public function supports($request): bool
    {
        return $request instanceof Refund && $request->getModel() instanceof ArrayAccess;
    }
}
