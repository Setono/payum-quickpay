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
