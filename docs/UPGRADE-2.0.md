# Upgrading from 1.x to 2.0

Version 2.0 replaces the library's hand-rolled Quickpay API client with the
[`setono/quickpay-php-sdk`](https://github.com/Setono/quickpay-php-sdk) package, hardens callback
handling, and modernizes the test suite. This is a major release with breaking changes.

## Requirements

- The SDK is built on PSR-18 / PSR-17 and discovers them via `php-http/discovery`. Make sure your
  project provides a PSR-18 client and PSR-17 factories (e.g. `composer require kriswallsmith/buzz
  nyholm/psr7`, or any other implementation).
- Until `setono/quickpay-php-sdk` has a stable release, your project needs
  `"minimum-stability": "alpha"` and `"prefer-stable": true`.

## Gateway options

- **`merchant` was removed.** Quickpay API v10 authenticates with the API key alone; the option was
  never used. Remove it from your gateway configuration (passing it is harmless — it is ignored).
- **Only `apikey` and `privatekey` are now required.** `language` is defaulted to `en`.
- **`agreement` is now optional** and maps to the payment-window `agreement_id`.
- **New options:** `synchronized` (run capture/refund/cancel synchronously instead of relying on the
  callback; default `false`, preserving 1.x behavior) and `branding_id` (payment-window branding).
- The misspelled, unused `syncronized` option was removed; use `synchronized`.

## Payment details contract

The details array stored on a payment (the `ArrayObject` model) now contains **only scalars**:

- `1.x` stored a hydrated `quickpayPayment` **object** alongside `quickpayPaymentId`.
- `2.0` stores only `quickpayPaymentId` (int) — the source of truth — plus `amount`, `currency`,
  `order_id`, `continue_url`, `cancel_url`, `callback_url`. The payment is re-fetched from Quickpay when
  needed.

If your code reads `$details['quickpayPayment']`, switch to fetching the payment via the SDK using
`$details['quickpayPaymentId']`.

## Callbacks are now verified

`NotifyAction` now verifies the `QuickPay-Checksum-Sha256` HMAC signature of every incoming callback
against your `privatekey` before acting on it; an invalid or unsigned callback is rejected with a `400`
response. Ensure:

- your gateway is configured with the correct `privatekey`, and
- your Payum HTTP-request bridge exposes request headers. The Symfony bridge
  (`Payum\Core\Bridge\Symfony\Action\GetHttpRequestAction`, used by Sylius/Symfony) does; the plain-PHP
  bridge does not, so a pure plain-PHP setup must supply a header-capable `GetHttpRequest` action.

## Quickpay sends callbacks to two different places

This is not new in 2.0, but it is not obvious and nothing in the gateway hints at it. Verified against a
live account (2026-08):

| Origin | Callback goes to |
|---|---|
| The customer paying in the hosted payment window | the **per-payment** `callback_url` on the payment link — the Payum notify token url `AuthorizeAction` mints |
| `capture` / `refund` / `cancel` issued through the API | the **account-wide** callback url (manager → Settings → Integration) |

So the gateway's notify token only ever receives the authorize callback. If the account-wide url is
empty — the default — **operation callbacks are not delivered anywhere at all**, and a shop waiting to
be told that its capture settled waits forever.

If you rely on those confirmations, you need both halves:

1. Set the account-wide callback url in Quickpay, and
2. point it at an endpoint that resolves the payment **from the callback body**, because that url is one
   static url for every payment and therefore cannot carry a `payum_token` — Payum's usual notify
   routing cannot work for it. Match on `order_id` (it is `order_prefix` + the Payum payment number),
   load your payment, and execute `Notify` against that model; `NotifyAction` needs only the model, and
   verifies the HMAC itself either way. `examples/e2e/listen.php` does exactly this.

Alternatively, skip callbacks for operations entirely: enable the `synchronized` option so
capture/refund/cancel block until the transaction is settled, or poll `GetStatus`, which re-fetches from
Quickpay.

## A partial refund no longer reports as fully refunded

`StatusAction` used to decide from the latest operation alone: if it was a refund, the payment was
marked **refunded** — even when only part of the money had been sent back. A shop reading that mark
would believe the customer got everything back while most of it was still held.

It now consults Quickpay's `balance` (what is still captured, i.e. captured minus refunded):

| Situation | Mark |
|---|---|
| latest operation is a capture | `captured` |
| latest operation is a refund, balance still positive | `captured` |
| latest operation is a refund, balance zero | `refunded` |
| `balance` absent from the response | `refunded`, as before |

Payum has no partial mark to reach for, so "still captured until fully refunded" is the closest honest
mapping. If your code branches on `STATUS_REFUNDED`, check it still behaves the way you want for a
partially refunded payment — it will now stay `captured` where it previously flipped to `refunded`.

Partial operations themselves are set through the new `capture_amount` / `refund_amount` details keys;
see the README.

## Order ids are now validated before they are sent

`ConvertPaymentAction` builds `order_id` as `order_prefix` + the Payum payment number, and now enforces
Quickpay's **4–20 character** rule on the result, throwing a `LogicException` if it falls outside.

Nothing that worked in 1.x stops working — Quickpay rejected those order ids anyway — but the failure
now happens at conversion time and as a different exception type, rather than as a `ValidationException`
after a network round trip. Check that your `order_prefix` and payment numbers together stay in range.

The same guard also rejects a payment with no number at all. That used to degrade silently: the
concatenation produced an order id consisting of nothing but the prefix, which with a prefix of 4+
characters is *valid*, so every such payment was created under the same order id.

## Removed / changed classes

These were internal implementation details; they are gone in 2.0:

- `Setono\Payum\Quickpay\Model\*` (`QuickpayPayment`, `QuickpayPaymentOperation`, `QuickpayPaymentLink`,
  `QuickpayModel`, `QuickpayCard`) — replaced by the SDK's `Setono\Quickpay\Response\Payment\*` DTOs and
  the `Setono\Quickpay\Enum\{PaymentState,OperationType}` enums. State logic now lives in the
  `Setono\Payum\Quickpay\Operations` helper.
- `Setono\Payum\Quickpay\Api` is no longer an HTTP client. It is now an immutable value object wrapping
  the SDK `Setono\Quickpay\Client\ClientInterface` and the gateway options (`payments()`, `getClient()`,
  `isAutoCapture()`, `createCallbackValidator()`, …). Its old `getPayment()` / `capturePayment()` /
  `checksum()` / `assertValidResponse()` methods were removed.

## Injecting a custom SDK client

You can pass a preconfigured SDK client through the new `quickpay.client` option (a
`Setono\Quickpay\Client\ClientInterface`) — useful for wiring a cached Valinor builder or a specific
PSR-18 client. When omitted, the gateway builds one from `apikey` via discovery.

The `synchronized` option is carried by the SDK client itself (`Client::__construct(..., synchronized:
true)`), and it can only be set there. An injected client must therefore be constructed with the same
value as the gateway's `synchronized` option — a mismatch throws a `LogicException` when the gateway is
built, rather than letting one of the two win silently.
