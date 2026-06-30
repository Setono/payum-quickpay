<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Action;

use ArrayAccess;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\Capture;
use Payum\Core\Security\GenericTokenFactoryAwareInterface;
use Payum\Core\Security\GenericTokenFactoryAwareTrait;
use Setono\Payum\Quickpay\Action\Api\ApiAwareTrait;
use Setono\Quickpay\Request\Payment\CaptureRequest;

class CaptureAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface, GenericTokenFactoryAwareInterface
{
    use GatewayAwareTrait;
    use ApiAwareTrait;
    use GenericTokenFactoryAwareTrait;

    /**
     * @param mixed|Capture $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $model = ArrayObject::ensureArrayObject($request->getModel());

        $this->api->payments()->capture(
            (int) $model['quickpayPaymentId'],
            new CaptureRequest(amount: (int) $model['amount']),
            synchronized: $this->api->isSynchronized(),
        );
    }

    public function supports($request): bool
    {
        return $request instanceof Capture && $request->getModel() instanceof ArrayAccess;
    }
}
