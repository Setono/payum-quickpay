<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Action\Api;

use ArrayAccess;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Setono\Payum\Quickpay\Details;
use Setono\Payum\Quickpay\Request\Api\ConfirmPayment;

/**
 * What the gateway does with a verified Quickpay callback: refresh the scalar snapshot in the
 * details (`balance`, `state`) from the payment the callback is about.
 *
 * It used to also capture, when `auto_capture` was on and the callback reported an approved
 * authorize. That was a second capture mechanism next to the payment link's own `auto_capture` flag,
 * and the two raced: the callback for the authorize could arrive before Quickpay had recorded the
 * capture it was already making, and the gateway would issue another. The link's flag is now the
 * only mechanism — a payment that should be captured on authorization is one whose link says so
 * ({@see \Setono\Payum\Quickpay\Action\CaptureAction}) — so this action never moves money.
 *
 * It stays a distinct request rather than being folded into NotifyAction so a consumer can replace
 * `payum.action.api.confirm_payment` to react to callbacks (dispatch an event, log, notify) without
 * touching the signature verification that runs before it.
 */
class ConfirmPaymentAction implements ActionInterface, GatewayAwareInterface, ApiAwareInterface
{
    use GatewayAwareTrait;
    use ApiAwareTrait;

    /**
     * @param mixed|ConfirmPayment $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $model = ArrayObject::ensureArrayObject($request->getModel());

        $payment = $this->api->payments()->getById(Details::paymentId($model));

        // Same keys SyncAction writes: the callback is the moment the payment changed, so this is the
        // freshest snapshot the details will get without another round trip.
        $model['balance'] = $payment->balance;
        $model['state'] = $payment->state;
    }

    public function supports($request): bool
    {
        return $request instanceof ConfirmPayment && $request->getModel() instanceof ArrayAccess;
    }
}
