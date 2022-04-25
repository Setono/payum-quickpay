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
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Request\Authorize;
use Payum\Core\Security\GenericTokenFactoryAwareInterface;
use Payum\Core\Security\GenericTokenFactoryAwareTrait;
use Setono\Payum\QuickPay\Action\Api\ApiAwareTrait;

class AuthorizeAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface, GenericTokenFactoryAwareInterface
{
    use GatewayAwareTrait;

    use ApiAwareTrait;

    use GenericTokenFactoryAwareTrait;

    /**
     * @param mixed|Authorize $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $model = ArrayObject::ensureArrayObject($request->getModel());

        if (null !== $token = $request->getToken()) {
            // Create callback url
            $model['callback_url'] = $this->tokenFactory
                ->createNotifyToken($token->getGatewayName(), $token->getDetails())
                ->getTargetUrl();
        }

        $quickpayPayment = $this->api->getPayment($model);

        // Create payment link
        $paymentLink = $this->api->createPaymentLink($quickpayPayment, $model);

        // Redirect to payment
        throw new HttpRedirect($paymentLink->getUrl());
    }

    public function supports($request): bool
    {
        return $request instanceof Authorize && $request->getModel() instanceof ArrayAccess;
    }
}
