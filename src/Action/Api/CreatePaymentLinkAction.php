<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Action\Api;

use ArrayAccess;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\LogicException;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Security\GenericTokenFactoryAwareInterface;
use Payum\Core\Security\GenericTokenFactoryAwareTrait;
use Setono\Payum\Quickpay\Details;
use Setono\Payum\Quickpay\Request\Api\CreatePaymentLink;
use Setono\Quickpay\Request\Payment\CreateLinkRequest;

/**
 * Creates the payment link and redirects the customer to Quickpay's hosted payment window.
 *
 * This is the only action that mints a token — the notify token whose url becomes the link's
 * `callback_url` — and therefore the only one implementing {@see GenericTokenFactoryAwareInterface},
 * the package's single remaining contact with payum/core's deprecated `GenericTokenFactoryInterface`
 * (issue #3). Keep it that way.
 */
class CreatePaymentLinkAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface, GenericTokenFactoryAwareInterface
{
    use GatewayAwareTrait;
    use ApiAwareTrait;
    use GenericTokenFactoryAwareTrait;

    /**
     * @param mixed|CreatePaymentLink $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $model = ArrayObject::ensureArrayObject($request->getModel());

        // Resolve the payment id first: minting a notify token or validating the urls is pointless
        // for a payment that does not exist at Quickpay yet.
        $paymentId = Details::paymentId($model);

        if (null !== $token = $request->getToken()) {
            // Build the server-to-server callback (notify) url.
            $model['callback_url'] = $this->tokenFactory
                ->createNotifyToken($token->getGatewayName(), $token->getDetails())
                ->getTargetUrl();
        }

        $model->validateNotEmpty(['continue_url', 'cancel_url', 'callback_url', 'amount']);

        $link = $this->api->payments()->createLink($paymentId, new CreateLinkRequest(
            amount: (int) $model['amount'],
            agreementId: $this->api->getAgreementId(),
            language: $this->api->getLanguage(),
            continueUrl: (string) $model['continue_url'],
            cancelUrl: (string) $model['cancel_url'],
            callbackUrl: (string) $model['callback_url'],
            paymentMethods: $this->api->getPaymentMethods(),
            autoCapture: $request->isAutoCapture(),
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
        return $request instanceof CreatePaymentLink && $request->getModel() instanceof ArrayAccess;
    }
}
