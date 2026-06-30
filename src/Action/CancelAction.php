<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Action;

use ArrayAccess;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\Http\HttpException;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\Cancel;
use Setono\Payum\QuickPay\Action\Api\ApiAwareTrait;

class CancelAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use GatewayAwareTrait;
    use ApiAwareTrait;

    /**
     * @param mixed|Cancel $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $model = ArrayObject::ensureArrayObject($request->getModel());

        $quickpayPayment = $this->api->getPayment($model);

        try {
            $this->api->cancelPayment($quickpayPayment, $model);
        } catch (HttpException $e) {
            try {
                $data = json_decode((string) $e->getResponse()->getBody(), true, 512, \JSON_THROW_ON_ERROR);
                if (!is_array($data) || !isset($data['message']) || !is_string($data['message'])) {
                    throw $e;
                }

                if (stripos($data['message'], 'Transaction in wrong state for this operation') === false) {
                    throw $e;
                }

                return;
            } catch (\Throwable $throwable) {
                throw $e;
            }
        }
    }

    public function supports($request): bool
    {
        return $request instanceof Cancel && $request->getModel() instanceof ArrayAccess;
    }
}
