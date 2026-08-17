<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Action;

use Composer\InstalledVersions;
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
use Setono\Quickpay\Request\Payment\Shopsystem;
use Setono\Quickpay\Response\Payment\Payment;

class ConvertPaymentAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use GatewayAwareTrait;
    use ApiAwareTrait;

    private const PACKAGE = 'setono/payum-quickpay';

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

            $payment = $this->findReusablePayment($orderId, $currency)
                ?? $this->api->payments()->create(new CreatePaymentRequest(
                    orderId: $orderId,
                    currency: $currency,
                    // Quickpay's "shopsystem" is what the payment was created with — it shows in the
                    // manager and tells Quickpay support which integration they are looking at. A
                    // shop's own Convert action (the Sylius plugin has one) can say something more
                    // specific.
                    shopsystem: new Shopsystem(name: self::PACKAGE, version: self::version()),
                ));

            $details['quickpayPaymentId'] = $payment->id;
            $details['order_id'] = $payment->orderId;
            $details['currency'] = $currency;
        } else {
            self::assertCurrencyUnchanged($details, $paymentModel->getCurrencyCode());
        }

        if (null !== $token = $request->getToken()) {
            // The customer comes back to the token's TARGET url, not its after url: that re-executes
            // the Authorize/Capture that sent them out, which is how the action gets to finish the
            // job (or find it done) the moment they are back — Payum's return-trip convention — rather
            // than leaving the outcome to a callback that may not have landed yet. The after url is
            // where Payum's controller sends them once the action has run. A cancel skips straight
            // there: nothing was done, so there is nothing to finish.
            $details['continue_url'] = $token->getTargetUrl();
            $details['cancel_url'] = $token->getAfterUrl();
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
     * Find-or-create, the safe half: the Quickpay payment that already carries this order id, if it
     * can be picked up where it was left — or null, meaning "create one".
     *
     * Quickpay enforces `order_id` uniqueness per account (a second create is a 400 "order_id already
     * exists on another payment" — verified live), and the order id is built from the Payum payment
     * NUMBER, which under Sylius is the ORDER number: every retry payment for an order — the customer
     * was declined, came back to the shop and pays again — carries the same number. Without this,
     * that retry died at Convert with a validation error, for the most ordinary of reasons.
     *
     * A payment is picked up only if nothing was ever approved on it — the window was never completed,
     * or the attempt was declined. That is exactly the retry case, and it can never make anything look
     * paid that is not: the entry-point actions treat such a payment like a fresh one and send the
     * customer back to the window. A payment that HAS an approved operation — authorized, captured,
     * refunded, cancelled — is never adopted silently: it may be this order's earlier payment that
     * really was paid, or another environment's payment under a prefix that should not be shared, and
     * either way it is for the shop to decide, so it is a clear exception rather than a claim of money.
     * The currency has to match too — the payment is what Quickpay charges in.
     *
     * @throws LogicException if a payment with this order id exists but cannot be adopted
     */
    private function findReusablePayment(string $orderId, string $currency): ?Payment
    {
        $existing = $this->api->payments()->findByOrderId($orderId);

        if (null === $existing) {
            return null;
        }

        $approved = $existing->latestApprovedOperation();
        if (null !== $approved) {
            throw new LogicException(sprintf(
                'A Quickpay payment with order id "%s" already exists (id %d, state %s) and has an approved %s. '
                . 'The gateway will not adopt it: if it is this order\'s earlier payment, carry its '
                . 'quickpayPaymentId over; if another shop or environment shares this Quickpay account, '
                . 'give each its own "order_prefix".',
                $orderId,
                $existing->id,
                $existing->state,
                $approved->type,
            ));
        }

        if ($existing->currency !== $currency) {
            throw new LogicException(sprintf(
                'A Quickpay payment with order id "%s" already exists (id %d) in %s, but this payment is in %s. '
                . 'A Quickpay payment cannot change currency; give the retry a different order id.',
                $orderId,
                $existing->id,
                $existing->currency,
                $currency,
            ));
        }

        return $existing;
    }

    /**
     * The installed version of this package, for the `shopsystem` Quickpay records on the payment.
     * Composer's runtime API knows it wherever the package was installed by Composer; "unknown" covers
     * a vendored copy.
     */
    private static function version(): string
    {
        return InstalledVersions::isInstalled(self::PACKAGE)
            ? (InstalledVersions::getPrettyVersion(self::PACKAGE) ?? 'unknown')
            : 'unknown';
    }

    /**
     * The gateway builds `order_id` by concatenating the `order_prefix` option with the Payum payment
     * number, and Quickpay accepts 4–20 characters of letters, digits, space, `.`, `_` and `-` — the
     * rule the SDK verified live and enforces in {@see CreatePaymentRequest::ORDER_ID_PATTERN}. It is
     * checked here, before the lookup and with the SDK's own pattern, so that the exception names the
     * two things the caller actually controls — the prefix and the number — rather than a field they
     * never set directly. It also catches an empty number: the concatenation would degrade to the bare
     * prefix, which, when the prefix alone is 4+ characters, is a *valid* order id, so every such
     * payment would be created under the SAME order id with nothing complaining at all.
     *
     * @throws LogicException if the resulting order id is not one Quickpay accepts
     */
    private static function assertOrderId(string $orderId): string
    {
        if (1 !== preg_match(CreatePaymentRequest::ORDER_ID_PATTERN, $orderId)) {
            throw new LogicException(sprintf(
                'The Quickpay order id "%s" (%d characters) is not one Quickpay accepts: 4–20 characters of '
                . 'letters, digits, space, ".", "_" and "-". It is built from the "order_prefix" gateway '
                . 'option and the Payum payment number — adjust the prefix so the two together fit.',
                $orderId,
                strlen($orderId),
            ));
        }

        return $orderId;
    }
}
