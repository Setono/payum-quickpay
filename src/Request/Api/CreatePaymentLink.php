<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Request\Api;

use Payum\Core\Request\Generic;
use Payum\Core\Security\TokenInterface;

/**
 * Internal request: create the Quickpay payment link for a payment and redirect the customer to
 * the hosted payment window.
 *
 * It is the one step {@see \Setono\Payum\Quickpay\Action\AuthorizeAction} and
 * {@see \Setono\Payum\Quickpay\Action\CaptureAction} share; they differ only in what the window
 * should do once the customer has paid — authorize and hold (`$autoCapture = false`), or authorize
 * and capture at once (`$autoCapture = true`). Which of the two public requests a consumer executes
 * is how it expresses that intent, in Payum's own vocabulary.
 *
 * The token — when the request is executed interactively — is carried explicitly so the handling
 * action can mint the notify (callback) token from it. It cannot travel through the model, and
 * {@see Generic}'s own token is only settable by constructing the request *with* the token as its
 * model, which would lose the details model this request must act on.
 */
final class CreatePaymentLink extends Generic
{
    /**
     * @param mixed $model the payment details
     */
    public function __construct(
        mixed $model,
        private readonly bool $autoCapture,
        ?TokenInterface $token = null,
    ) {
        parent::__construct($model);

        // Generic documents $token as non-nullable while defaulting it to null; assigning null
        // explicitly would only trip static analysis over that inconsistency.
        if (null !== $token) {
            $this->token = $token;
        }
    }

    /**
     * Whether Quickpay should capture the payment automatically once the customer has authorized it.
     */
    public function isAutoCapture(): bool
    {
        return $this->autoCapture;
    }
}
