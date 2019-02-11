<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Action;

use Setono\Payum\QuickPay\Action\Api\ApiAwareTrait;
use Setono\Payum\QuickPay\Model\QuickPayPayment;
use Setono\Payum\QuickPay\Model\QuickPayPaymentLink;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Request\Authorize;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Security\GenericTokenFactoryAwareInterface;
use Payum\Core\Security\GenericTokenFactoryAwareTrait;

class AuthorizeAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface, GenericTokenFactoryAwareInterface
{
    use GatewayAwareTrait;
    use ApiAwareTrait;
    use GenericTokenFactoryAwareTrait;

    /**
     * {@inheritdoc}
     *
     * @param Authorize $request
     */
    public function execute($request)
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $model = ArrayObject::ensureArrayObject($request->getModel());
        $token = $request->getToken();

        $quickpayPayment = $this->api->getPayment($model);

        if ($quickpayPayment instanceof QuickPayPayment) {
            // Create callback url
            $model['callback_url'] = $this->tokenFactory->createNotifyToken(
                $token->getGatewayName(),
                $token->getDetails()
            )->getTargetUrl();

            // Create payment link
            $paymentLink = $this->api->createPaymentLink($quickpayPayment, $model);

            // Redirect to payment
            if ($paymentLink instanceof QuickPayPaymentLink) {
                throw new HttpRedirect($paymentLink->getUrl());
            }
        }

        throw new \LogicException('Payment could not be initialized');
    }

    /**
     * {@inheritdoc}
     */
    public function supports($request)
    {
        return
            $request instanceof Authorize &&
            $request->getModel() instanceof \ArrayAccess;
    }
}
