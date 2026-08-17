# Upgrading from 1.x to 2.0

Version 2.0 replaces the library's hand-rolled Quickpay API client with the
[`setono/quickpay-php-sdk`](https://github.com/Setono/quickpay-php-sdk) package, hardens callback
handling, and modernizes the test suite. This is a major release with breaking changes.

## Requirements

- `payum/core` **1.7.5 or newer** (`^1.7.5`, was `^1.6`). That is the release that marks
  `GetHttpRequest` `#[\AllowDynamicProperties]`; the `headers` property the callback verification reads
  is a dynamic one, and on older payum/core every callback would raise a "creation of dynamic property"
  deprecation on PHP 8.2+ (an error on PHP 9).
- The SDK is built on PSR-18 / PSR-17 and discovers them via `php-http/discovery`. Make sure your
  project provides a PSR-18 client and PSR-17 factories (e.g. `composer require kriswallsmith/buzz
  nyholm/psr7`, or any other implementation).
- `setono/quickpay-php-sdk` is stable as of `1.0.0`, so **no `minimum-stability` change is needed**. If
  you tracked the 2.0 alphas and added `"minimum-stability": "beta"` for the SDK's pre-releases, you can
  drop it again (assuming nothing else in your project needs it).

## Gateway options

- **`merchant` was removed.** Quickpay API v10 authenticates with the API key alone; the option was
  never used. Remove it from your gateway configuration (passing it is harmless — it is ignored).
- **Options renamed to say what they are:**

  | 1.x | 2.0 |
  |-----|-----|
  | `apikey` | `api_key` |
  | `privatekey` | `private_key` |
  | `agreement` | `agreement_id` |

  The credentials are snake_case like every other multi-word option, and `agreement_id` matches its
  sibling `branding_id` — both are optional integer payment-link ids.

  All three 1.x names still work as **deprecated aliases**, so an existing gateway configuration —
  including one stored in a database, as Sylius does — keeps working untouched. Prefer the new names;
  the aliases go in 3.0.

  Worth updating `agreement` sooner rather than later: unlike the credentials it is optional, so if the
  aliases are ever removed while your config still uses the old name, it fails **silently** — the
  payment link is created without an agreement id and Quickpay falls back to the account default.
- **Only the two credentials are required.** `language` is defaulted to `en`, and `agreement_id` is
  optional.
- **New options:** `synchronized` (run capture/refund/cancel synchronously instead of relying on the
  callback; default `false`, preserving 1.x behavior — a declined operation then throws, see below) and
  `branding_id` (payment-window branding).
- The misspelled, unused `syncronized` option was removed; use `synchronized`.
- **`auto_capture` is deprecated** — execute `Capture` instead of `Authorize` to capture at
  authorization; see the next section.

## `Capture` now drives the checkout — and `auto_capture` is deprecated

In 1.x, `Capture` was strictly the money operation: executed against a payment that had not been
authorized, it asked Quickpay to capture money that was never held and failed with a
`ValidationException`. The interactive step was `Authorize` only, so a default Sylius checkout — which
executes `Capture` — did not work until you found `use_authorize: true`.

2.0 follows Payum's convention, which is also what Payum's controllers and Sylius expect:

- **`Capture` on a payment that is not authorized yet opens the payment window**, with `auto_capture`
  set on the link, so Quickpay captures the moment the card is authorized. Executing `Capture` is how
  you say "take the money".
- **`Authorize`** opens an auth-only window, exactly as before.
- **Both are idempotent.** They decide from the payment's operations at Quickpay, so re-executing
  either — the return trip, a refresh, a retry — never creates a second link. And a `Capture` on a
  payment whose link captures by itself never issues a capture of its own: only Quickpay moves that
  money, so it cannot be moved twice.
- **`Capture` on an authorized payment** (an `Authorize` flow settling later, possibly in
  `capture_amount` instalments) captures through the API, as before.

Because "capture at authorization" is now expressed by executing `Capture`, the **`auto_capture`
gateway option is deprecated**. It still works — it makes an `Authorize` flow capture on
authorization, which is what it always did — and it goes in 3.0. Prefer executing `Capture`.

Two knock-on changes:

- **`continue_url` is now the token's *target* url**, not its after url — so the customer returns to
  the `Capture`/`Authorize` that sent them out, which runs again, finds the outcome and completes;
  Payum's controller then sends them on to the after url. `cancel_url` stays the after url. If you
  serve the token urls yourself (outside Payum's controllers), the return trip now hits them; see
  `examples/e2e/listen.php` for what a controller has to do. Under Payum's own capture/authorize
  controllers, nothing changes for you.
- **`ConfirmPaymentAction` no longer captures.** In 1.x it captured on the authorize callback when
  `auto_capture` was on — a second capture mechanism next to the link's own flag, and the two raced:
  the callback could arrive before Quickpay had recorded the capture it was already making, and the
  gateway would issue another. The link's flag is now the only mechanism, and the callback path only
  refreshes the details (`balance`, `state`). It never moves money.

The public actions no longer implement `GenericTokenFactoryAwareInterface`; the token is minted by the
new internal `CreatePaymentLinkAction` (`payum.action.api.create_payment_link`), which `Authorize` and
`Capture` delegate to.

## Payment details contract

The details array stored on a payment (the `ArrayObject` model) now contains **only scalars**:

- `1.x` stored a hydrated `quickpayPayment` **object** alongside `quickpayPaymentId`.
- `2.0` stores only `quickpayPaymentId` (int) — the source of truth — plus `amount`, `currency`,
  `order_id`, `continue_url`, `cancel_url`, `callback_url`. The payment is re-fetched from Quickpay when
  needed.
- Any action that already fetches the payment also writes **`balance`** (what is still captured, i.e.
  captured minus refunded) back into the details: `Capture`, `Authorize`, `GetStatus`, `Notify`, the
  new `Sync`, and `Refund` on its default path. It costs no extra API call and it is the one figure
  Payum's status marks cannot express. `Sync` and `Notify` additionally write `state`.

If your code reads `$details['quickpayPayment']`, switch to fetching the payment via the SDK using
`$details['quickpayPaymentId']`.

## Callbacks are now verified

`NotifyAction` now verifies the `QuickPay-Checksum-Sha256` HMAC signature of every incoming callback
against your `private_key` before acting on it; an invalid or unsigned callback is rejected with a `400`
response. Ensure:

- your gateway is configured with the correct `private_key`, and
- your Payum HTTP-request bridge exposes request headers. The Symfony bridge
  (`Payum\Core\Bridge\Symfony\Action\GetHttpRequestAction`, used by Sylius/Symfony) does; the plain-PHP
  bridge does not — so the gateway factory replaces payum's plain-PHP action, when that is what it
  finds under `payum.action.get_http_request`, with the
  `Setono\Payum\Quickpay\Bridge\PlainPhp\Action\HeaderAwareGetHttpRequestAction` this package ships
  (changed after `2.0.0-beta.1`: registering it yourself via `addCoreGatewayFactoryConfig()` still
  works and is now simply redundant). A `GetHttpRequest` action of your own is left alone; make sure it
  sets `headers`.

## Quickpay sends callbacks to two different places

This is not new in 2.0, but it is not obvious and nothing in the gateway hints at it. Verified against a
live account (2026-08):

| Origin | Callback goes to |
|---|---|
| The customer paying in the hosted payment window | the **per-payment** `callback_url` on the payment link — the Payum notify token url the gateway mints when it creates the link |
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

## A declined synchronized operation throws

> Changed after `2.0.0-beta.1`.

Quickpay reports a declined capture, refund or cancel with a `2xx` and the outcome on the operation
(`qp_status_code` other than `20000`), never as an HTTP error, so nothing in the SDK throws for it. On
the default asynchronous path that is fine — the response only carries the operation as pending, and the
outcome arrives via the callback or the next `GetStatus`. With `synchronized`, though, the response *is*
the outcome, and until now the actions ignored it: a declined synchronized capture returned exactly like
an approved one.

`CaptureAction`, `RefundAction` and `CancelAction` now read the newest operation of the type they just
issued off the returned payment and throw `Setono\Payum\Quickpay\Exception\OperationRejectedException`
(a `Payum\Core\Exception\RuntimeException`) when it has completed and was not approved. The exception
carries `getPaymentId()` and `getOperation()` (the SDK `Operation`, with `qpStatusCode`/`qpStatusMsg` and
the acquirer's `aqStatusCode`/`aqStatusMsg`). A `capture_amount`/`refund_amount` instruction is left in
place, as for any failed call. Nothing changes for asynchronous operations.

## Cancelling a captured payment throws

`CancelAction` does not swallow Quickpay's rejection of a cancel against an already captured or
cancelled payment. It surfaces as a `Setono\Quickpay\Exception\ValidationException` ("Payment is not in
a valid state for cancel").

In practice this is not a change: the code did try to swallow it, by matching an error message Quickpay
no longer sends, so the exception has been reaching callers regardless. The attempt has been removed
rather than repaired — keying a money-affecting decision on an unstable message string is fragile, and
reporting success for a payment whose money is still held is worse than failing.

If you were relying on cancel being a no-op, catch the exception yourself:

```php
try {
    $gateway->execute(new Cancel($payment));
} catch (ValidationException $e) {
    // already captured or cancelled — decide whether to refund instead
}
```

## A refund with no amount refunds the balance

> Changed after `2.0.0-alpha.1`.

`RefundAction` used to default to the payment's full `amount`. That is wrong whenever anything has
already been refunded — including a refund made directly in the Quickpay manager — because a payment is
refundable only up to what is captured, so the call was guaranteed to be rejected.

An unqualified `Refund` now fetches the payment and refunds its `balance`, which *is* the maximal
refundable amount. Nothing left to refund throws a `LogicException` naming the payment and the balance,
rather than surfacing Quickpay's generic validation error.

An explicit `refund_amount` is unaffected and still skips the fetch entirely.

If you were relying on a bare `Refund` to refund the original total, note that it now refunds only what
remains — which is the only amount that could ever have succeeded.

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
PSR-18 client. When omitted, the gateway builds one from `api_key` via discovery.

The `synchronized` option is carried by the SDK client itself (`Client::__construct(..., synchronized:
true)`), and it can only be set there. An injected client must therefore be constructed with the same
value as the gateway's `synchronized` option — a mismatch throws a `LogicException` when the gateway is
built, rather than letting one of the two win silently.
