<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Action;

use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\LogicException;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Model\PaymentInterface;
use Payum\Core\Request\Convert;
use Setono\Payum\Quickpay\Action\Api\ApiAwareTrait;
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
            // Only the create path needs a currency — a payment that already carries a
            // quickpayPaymentId is unaffected.
            $currency = self::assertCurrencyCode(
                $paymentModel->getCurrencyCode(),
                $paymentModel->getNumber(),
            );

            $payment = $this->api->payments()->create(new CreatePaymentRequest(
                orderId: $this->api->getOrderPrefix() . $paymentModel->getNumber(),
                currency: $currency,
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

    /**
     * Payum documents {@see PaymentInterface::getCurrencyCode()} as `@return string`, but the property is
     * nullable on Payum's own payment model, so the docblock cannot be trusted at runtime. The SDK
     * requires a non-null currency on {@see CreatePaymentRequest}, so without this a payment saved
     * without one would blow up as a `TypeError` raised inside the DTO, naming neither the payment nor
     * the cause. Both parameters are `mixed` for that reason — it is what they really are, and it stops
     * static analysis from folding the check away as always-true.
     *
     * @throws LogicException if the payment carries no usable currency code
     */
    private static function assertCurrencyCode(mixed $currencyCode, mixed $paymentNumber): string
    {
        if (!is_string($currencyCode) || '' === $currencyCode) {
            throw new LogicException(sprintf(
                'Cannot create a Quickpay payment for the Payum payment "%s": it has no currency code.',
                is_string($paymentNumber) && '' !== $paymentNumber ? $paymentNumber : '(unknown)',
            ));
        }

        return $currencyCode;
    }
}
