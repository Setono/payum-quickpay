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
use Setono\Payum\Quickpay\Details;
use Setono\Payum\Quickpay\Operations;
use Setono\Payum\Quickpay\Request\Api\ConfirmPayment;
use Setono\Quickpay\Enum\OperationType;
use Setono\Quickpay\Request\Payment\CaptureRequest;

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

        $paymentId = Details::paymentId($model);

        $payment = $this->api->payments()->getById($paymentId);

        // Persist the balance before any early return below, so a callback for a payment with nothing
        // to confirm still refreshes it.
        $model['balance'] = $payment->balance;

        $latestOperation = Operations::latest($payment->operations);
        if (null === $latestOperation) {
            // A payment can legitimately have no operations yet — Quickpay fires a callback when the
            // payment is merely created, which becomes visible as soon as an account-wide callback url
            // (Settings → Integration) is configured. There is nothing to confirm, so do nothing.
            // Throwing here would 500 the notify endpoint, and Quickpay would retry a callback that
            // can never succeed.
            return;
        }

        if ($this->api->isAutoCapture() && OperationType::Authorize === $latestOperation->type()) {
            $authorizedAmount = Operations::authorizedAmount($payment->operations);
            $expectedAmount = (int) $model['amount'];

            if ($authorizedAmount !== $expectedAmount) {
                throw new LogicException(sprintf(
                    'Authorized amount does not match. Authorized %s expected %s',
                    $authorizedAmount,
                    $expectedAmount,
                ));
            }

            $this->api->payments()->capture(
                $paymentId,
                new CaptureRequest(amount: $expectedAmount),
            );
        }
    }

    public function supports($request): bool
    {
        return $request instanceof ConfirmPayment && $request->getModel() instanceof ArrayAccess;
    }
}
