<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Action;

use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Model\PaymentInterface;
use Payum\Core\Request\Convert;
use Setono\Payum\QuickPay\Action\Api\ApiAwareTrait;
use Setono\Quickpay\Request\Payment\CreatePaymentRequest;

class ConvertPaymentAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use GatewayAwareTrait;
    use ApiAwareTrait;

    /**
     * @param mixed|Convert $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var PaymentInterface $paymentModel */
        $paymentModel = $request->getSource();

        $details = ArrayObject::ensureArrayObject($paymentModel->getDetails());
        $details['amount'] = $paymentModel->getTotalAmount();
        $details['currency'] = $paymentModel->getCurrencyCode();

        // Only scalars are stored in the details so they survive serialization by the consumer.
        // `quickpayPaymentId` is the single source of truth; the payment is re-fetched when needed.
        if (!isset($details['quickpayPaymentId'])) {
            $payment = $this->api->payments()->create(new CreatePaymentRequest(
                orderId: $this->api->getOrderPrefix() . $paymentModel->getNumber(),
                currency: $paymentModel->getCurrencyCode(),
            ));

            $details['quickpayPaymentId'] = $payment->id;
            $details['order_id'] = $payment->orderId;
        }

        if (null !== $token = $request->getToken()) {
            $details['continue_url'] = $details['cancel_url'] = $token->getAfterUrl();
        }

        $request->setResult((array) $details);
    }

    public function supports($request): bool
    {
        return $request instanceof Convert && $request->getSource() instanceof PaymentInterface && 'array' === $request->getTo();
    }
}
