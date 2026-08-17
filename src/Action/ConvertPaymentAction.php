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
     * Quickpay's accepted `order_id` length, verified against the live API.
     */
    private const ORDER_ID_MIN_LENGTH = 4;

    private const ORDER_ID_MAX_LENGTH = 20;

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

        // Only scalars are stored in the details so they survive serialization by the consumer.
        // `quickpayPaymentId` is the single source of truth; the payment is re-fetched when needed.
        if (!isset($details['quickpayPaymentId'])) {
            // Only the create path needs these — a payment that already carries a quickpayPaymentId is
            // unaffected.
            $number = self::assertNotEmptyString($paymentModel->getNumber(), 'number');
            $currency = self::assertNotEmptyString($paymentModel->getCurrencyCode(), 'currency code', $number);
            $orderId = self::assertOrderId($this->api->getOrderPrefix() . $number);

            $payment = $this->api->payments()->create(new CreatePaymentRequest(
                orderId: $orderId,
                currency: $currency,
            ));

            $details['quickpayPaymentId'] = $payment->id;
            $details['order_id'] = $payment->orderId;
            $details['currency'] = $currency;
        } else {
            self::assertCurrencyUnchanged($details, $paymentModel->getCurrencyCode());
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
     * Payum documents {@see PaymentInterface::getNumber()} and {@see PaymentInterface::getCurrencyCode()}
     * as `@return string`, but both properties are nullable on Payum's own payment model, so neither
     * docblock can be trusted at runtime. `$value` is `mixed` for that reason — it is what these really
     * are, and it stops static analysis from folding the check away as always-true.
     *
     * The two fail differently without this. A missing currency reaches the non-nullable
     * {@see CreatePaymentRequest::$currency} and blows up as a `TypeError` raised inside the DTO,
     * naming neither the payment nor the cause. A missing number is worse: it is concatenated, so it
     * degrades silently — see {@see self::assertOrderId()}.
     *
     * @throws LogicException if the payment carries no usable value
     */
    private static function assertNotEmptyString(mixed $value, string $what, string $paymentNumber = ''): string
    {
        if (!is_string($value) || '' === $value) {
            throw new LogicException(sprintf(
                'Cannot create a Quickpay payment for the Payum payment "%s": it has no %s.',
                '' !== $paymentNumber ? $paymentNumber : '(unknown)',
                $what,
            ));
        }

        return $value;
    }

    /**
     * A Quickpay payment's currency is fixed when it is created — the authorize will happen in that
     * currency no matter what the details say. Before this guard, a Payum payment whose currency
     * changed after conversion simply had its stored `currency` overwritten: the shop then believed
     * one currency while Quickpay kept charging in the other, with the amount silently read in the
     * wrong unit. A drifted currency is a hard error; the payment must be cancelled and a fresh one
     * converted instead.
     *
     * A model without a currency (both properties are nullable on Payum's model) leaves the stored
     * value alone, and a model that agrees is a no-op refresh.
     *
     * @param ArrayObject<string, mixed> $details
     *
     * @throws LogicException if the model's currency no longer matches the created payment's
     */
    private static function assertCurrencyUnchanged(ArrayObject $details, mixed $currency): void
    {
        if (!is_string($currency) || '' === $currency) {
            return;
        }

        $stored = $details['currency'] ?? null;

        if (is_string($stored) && '' !== $stored && $stored !== $currency) {
            throw new LogicException(sprintf(
                'The Quickpay payment %s was created in %s, but the Payum payment now says %s. A Quickpay '
                . 'payment cannot change currency — cancel it and convert a new payment instead.',
                is_scalar($details['quickpayPaymentId']) ? (string) $details['quickpayPaymentId'] : '(unknown)',
                $stored,
                $currency,
            ));
        }

        $details['currency'] = $currency;
    }

    /**
     * Quickpay requires `order_id` to be 4–20 characters, and the gateway builds it by concatenating
     * the `order_prefix` option with the Payum payment number. Checking the result here turns two
     * failures into one clear exception at the call site:
     *
     * - too long, or too short, would otherwise come back as a `ValidationException` after a network
     *   round trip, blaming a field the caller never set directly;
     * - and if the number were ever empty, the concatenation would degrade to the bare prefix — which,
     *   when the prefix is itself 4+ characters, is a *valid* order id, so every such payment would be
     *   created under the SAME order id with nothing complaining at all.
     *
     * `strlen()` counts bytes rather than characters. Quickpay's order ids are ASCII in practice (a
     * Payum payment number plus your prefix), and pulling in ext-mbstring for this would cost more than
     * the check is worth.
     *
     * @throws LogicException if the resulting order id falls outside Quickpay's accepted length
     */
    private static function assertOrderId(string $orderId): string
    {
        $length = strlen($orderId);

        if ($length < self::ORDER_ID_MIN_LENGTH || $length > self::ORDER_ID_MAX_LENGTH) {
            throw new LogicException(sprintf(
                'The Quickpay order id "%s" is %d characters, but Quickpay requires between %d and %d. '
                . 'It is built from the "order_prefix" gateway option and the Payum payment number — '
                . 'adjust the prefix so the two together stay within that range.',
                $orderId,
                $length,
                self::ORDER_ID_MIN_LENGTH,
                self::ORDER_ID_MAX_LENGTH,
            ));
        }

        return $orderId;
    }
}
