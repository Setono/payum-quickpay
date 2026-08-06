<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Action;

use ArrayAccess;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\LogicException;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Request\Authorize;
use Payum\Core\Security\GenericTokenFactoryAwareInterface;
use Payum\Core\Security\GenericTokenFactoryAwareTrait;
use Setono\Payum\Quickpay\Action\Api\ApiAwareTrait;
use Setono\Quickpay\Request\Payment\CreateLinkRequest;

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
            // Build the server-to-server callback (notify) url.
            $model['callback_url'] = $this->tokenFactory
                ->createNotifyToken($token->getGatewayName(), $token->getDetails())
                ->getTargetUrl();
        }

        $model->validateNotEmpty(['continue_url', 'cancel_url', 'callback_url', 'amount']);

        $link = $this->api->payments()->createLink((int) $model['quickpayPaymentId'], new CreateLinkRequest(
            amount: (int) $model['amount'],
            agreementId: $this->api->getAgreementId(),
            language: $this->api->getLanguage(),
            continueUrl: (string) $model['continue_url'],
            cancelUrl: (string) $model['cancel_url'],
            callbackUrl: (string) $model['callback_url'],
            paymentMethods: $this->api->getPaymentMethods(),
            autoCapture: $this->api->isAutoCapture(),
            brandingId: $this->api->getBrandingId(),
        ));

        if (null === $link->url) {
            throw new LogicException('Quickpay did not return a payment link url');
        }

        // Redirect the customer to the Quickpay payment window.
        throw new HttpRedirect($link->url);
    }

    public function supports($request): bool
    {
        return $request instanceof Authorize && $request->getModel() instanceof ArrayAccess;
    }
}
