# Payum Quickpay Gateway

[![Latest Version on Packagist][ico-version]][link-packagist]
[![Software License][ico-license]](LICENSE)
[![Build Status][ico-github-actions]][link-github-actions]
[![Code Coverage][ico-code-coverage]][link-code-coverage]
[![Mutation testing badge][ico-infection]][link-infection]

This component enables the use of Quickpay with Payum. Under the hood it uses the
[`setono/quickpay-php-sdk`](https://github.com/Setono/quickpay-php-sdk) client.

> **Upgrading from 1.x?** See [`docs/UPGRADE-2.0.md`](docs/UPGRADE-2.0.md).

## Installation

```bash
composer require setono/payum-quickpay
```

The SDK relies on [HTTPlug discovery](https://docs.php-http.org/en/latest/discovery.html), so your
project must provide a [PSR-18 HTTP client](https://packagist.org/providers/psr/http-client-implementation)
and [PSR-17 message factories](https://packagist.org/providers/psr/http-factory-implementation). If you do
not already have them, install e.g.:

```bash
composer require kriswallsmith/buzz nyholm/psr7
```

## Configuration

The gateway requires your Quickpay **API key** (Settings → API user) and **private key** (Settings →
Integration — used to verify callback signatures). Other options are optional:

| Option            | Default | Description                                                          |
|-------------------|---------|----------------------------------------------------------------------|
| `api_key`         | —       | **Required.** Quickpay API key.                                      |
| `private_key`     | —       | **Required.** Private key; used to verify the callback HMAC.         |
| `auto_capture`    | `0`     | **Deprecated.** Makes an `Authorize` flow capture on authorization; execute `Capture` instead. |
| `payment_methods` | `''`    | Restrict the payment-window methods (e.g. `creditcard`). See below.  |
| `order_prefix`    | `''`    | Prepended to the Payum payment number to form the Quickpay order id. |
| `language`        | `en`    | Payment-window language.                                             |
| `synchronized`    | `false` | Run capture/refund/cancel synchronously; a decline then throws (see below). |
| `agreement_id`    | `''`    | Optional payment-window agreement id.                                |
| `branding_id`     | `''`    | Optional payment-window branding id.                                 |

The 1.x names `apikey`, `privatekey` and `agreement` are still accepted as deprecated aliases, so an
existing gateway configuration keeps working. They will be removed in 3.0.

The factory name is exposed as a constant, so consumers looking gateways up by `factoryName`, tagging
services, or guarding "is this a Quickpay payment?" need not repeat the literal:

```php
use Setono\Payum\Quickpay\QuickpayGatewayFactory;

QuickpayGatewayFactory::NAME;   // 'quickpay'
```

```php
<?php

use Payum\Core\GatewayFactoryInterface;
use Payum\Core\PayumBuilder;
use Setono\Payum\Quickpay\QuickpayGatewayFactory;

$payum = (new PayumBuilder())
    ->addDefaultStorages() // or your own token/payment storages
    ->addGatewayFactory(QuickpayGatewayFactory::NAME, static function (array $config, GatewayFactoryInterface $coreGatewayFactory): QuickpayGatewayFactory {
        return new QuickpayGatewayFactory($config, $coreGatewayFactory);
    })
    ->addGateway('quickpay', [
        'factory' => QuickpayGatewayFactory::NAME,
        'api_key' => 'your-api-key',
        'private_key' => 'your-private-key',
        // any of the options above, e.g.
        'order_prefix' => 'shop-',
    ])
    ->getPayum();
```

Both credentials are validated when the gateway is built, so `getGateway('quickpay')` throws with a
message naming the missing option rather than the first payment failing with a `401`.

### `payment_methods`

Quickpay takes this as one comma-separated list of method names and/or groups, each optionally prefixed
with `!` to exclude it — see the
[payment methods appendix](https://learn.quickpay.net/tech-talk/appendixes/payment-methods/) for the
accepted values. Naming any method turns the list into an allowlist: everything not named is rejected.
Leave it empty to apply your account's own configuration.

The gateway accepts either shape, so a list is fine where that reads better:

```php
'payment_methods' => 'creditcard,!jcb,!visa-us',
// or
'payment_methods' => ['creditcard', '!jcb', '!visa-us'],
```

## Usage

### The flow — `Capture` or `Authorize` starts it, like everywhere in Payum

Quickpay is an authorize/capture PSP behind a hosted payment window, and the gateway maps that onto
Payum's requests the way Payum's own controllers — and Sylius — expect:

1. **`Convert`** turns your Payum payment into the details array and creates the payment at Quickpay.
2. **`Capture`** or **`Authorize`** against the fresh payment creates the payment link and
   **redirects the customer to the Quickpay payment window** (Payum's `HttpRedirect` reply). Which
   one you execute is how you say what the window should do once the card is authorized:
   - `Capture` — take the money right away. The link carries `auto_capture`, so Quickpay captures
     the moment the authorization is approved. This is what Payum's capture controller and Sylius's
     default checkout execute, and it is the common case.
   - `Authorize` — reserve the amount only. Settle later with `Capture` (in full or in instalments),
     or release it with `Cancel`.
3. Quickpay confirms with a signed **callback** (see below), which `Notify` verifies. Then it sends
   the customer back to the **same token url**, where Payum re-executes the request from step 2 —
   which now finds the payment authorized/captured and simply completes, and Payum's controller
   sends the customer on to the after url. Nothing waits for the callback to have arrived.
4. On an authorized payment, **`Capture`**, **`Refund`** and **`Cancel`** are pure money operations:
   they call the API and never redirect anywhere.

The interactive step is idempotent: `Capture`/`Authorize` decide from the payment's operations at
Quickpay, so re-running either (the return trip, a refresh, a retry) never creates a second link
and — the rule that matters — a `Capture` on a payment whose link captures by itself never issues a
capture of its own. Only Quickpay moves that money, so it cannot be moved twice.

The one exception: Quickpay's **own capture can be declined** by the acquirer (an authorization
approved, the capture a moment later not — Quickpay tries once and does not retry). The payment then
reports `authorized`, and only a capture issued through the API can still settle it. The return trip
still moves no money — the customer lands on your after url with the payment `authorized`, which is
the truth — but a **programmatic `Capture`** (your own code, a state-machine hook: no token) captures
through the API exactly like on a plain link, `capture_amount` included. Without that, such a payment
was stuck at Quickpay's manager and every `Capture` a silent no-op.

```php
<?php

use Payum\Core\Request\Capture;

// Checkout, the common case: send the customer through Capture. In a framework this is Payum's
// capture controller (Sylius does it for you); standalone, mint a capture token and redirect to it.
$token = $payum->getTokenFactory()->createCaptureToken('quickpay', $payment, 'after-checkout.php');
header('Location: ' . $token->getTargetUrl());

// Authorize-then-settle instead: an authorize token at checkout…
$token = $payum->getTokenFactory()->createAuthorizeToken('quickpay', $payment, 'after-checkout.php');
// …and later — e.g. when the order ships — a programmatic Capture of what was authorized:
$payum->getGateway('quickpay')->execute(new Capture($payment->getDetails()));
```

The `auto_capture` gateway option is **deprecated** in favour of this: it made an `Authorize` flow
capture on authorization too, which is exactly what executing `Capture` means. It still works for
existing configurations and goes in 3.0.

### Callbacks

Quickpay confirms operations with a signed server-to-server callback. `NotifyAction` verifies the
`QuickPay-Checksum-Sha256` HMAC against your `private_key` before acting on one; an unsigned or
tampered callback is rejected with a `400`. Quickpay retries an undelivered or non-2xx callback up to
24 times with growing delays, so a wrong `private_key` shows up as a stream of rejected callbacks in
your logs rather than as silence — and a callback your endpoint fails on will come back. Two things
are easy to get wrong:

**Quickpay sends callbacks to two different places.** The payment-window authorize goes to the
per-payment callback url the gateway builds (a Payum notify token). But `capture`/`refund`/`cancel`
issued through the API go to the **account-wide** callback url (Quickpay manager → Settings →
Integration) — which is empty by default, so those callbacks are simply not delivered anywhere, and
a shop waiting to hear that its capture settled waits forever. That url is one static url for every
payment and cannot carry a `payum_token`, so an endpoint for it must resolve the payment from the
callback body's `order_id` (`order_prefix` + the Payum payment number) and execute `Notify` against
that model. See [`docs/UPGRADE-2.0.md`](docs/UPGRADE-2.0.md) for the full picture and
`examples/e2e/listen.php` for a working endpoint. Alternatively, skip operation callbacks entirely:
set `synchronized` to `true` so the operations block until settled, or poll with `Sync`/`GetStatus`.

**A declined operation is not an HTTP error.** Quickpay answers `2xx` and puts the outcome on the
operation, so the SDK does not throw for it. Asynchronously (the default) the response only says the
operation is pending and the outcome arrives via the callback or the next `GetStatus`. With
`synchronized` the response *is* the outcome, and the gateway reads it: a capture, refund or cancel the
acquirer declined throws `Setono\Payum\Quickpay\Exception\OperationRejectedException` (a Payum
`RuntimeException` carrying the payment id and the declined `Operation` with Quickpay's and the
acquirer's status code and message). Any partial-amount instruction (`capture_amount`/`refund_amount`)
survives it, like for any failed call.

**Outside Symfony, headers need help — and the gateway provides it.** payum/core's plain-PHP
`GetHttpRequest` bridge does not expose request headers, and without the checksum header every
callback would be rejected as unsigned. The gateway factory therefore replaces payum's plain-PHP
action with the shipped `Setono\Payum\Quickpay\Bridge\PlainPhp\Action\HeaderAwareGetHttpRequestAction`
(a subclass that rebuilds the headers from `$_SERVER`) whenever it finds payum's own — the default on
a plain-PHP Payum — so nothing needs configuring. Symfony/Sylius (whose bridge exposes the headers) and
any `payum.action.get_http_request` you configured yourself are left alone; if you do wire your own,
make sure it sets `headers`.

### The payment details

The gateway stores **only scalars** in the details, so they survive whatever serialization your storage
uses. `quickpayPaymentId` is the single source of truth — everything else is a snapshot.

| Key | Written by | Meaning |
|-----|------------|---------|
| `quickpayPaymentId` | `Convert` | The Quickpay payment id. Everything else is re-fetched with it. |
| `amount`, `currency` | `Convert` | The payment total, in minor units. |
| `order_id` | `Convert` | `order_prefix` + the Payum payment number. |
| `continue_url` | `Convert` | The token's **target** url: the customer returns to it, and the `Capture`/`Authorize` that sent them out runs again to finish. |
| `cancel_url` | `Convert` | The token's after url. |
| `callback_url` | `Capture`, `Authorize` | The notify token url given to Quickpay. |
| `balance` | every action that fetches the payment: `Capture`, `Authorize`, `GetStatus`, `Sync`, `Notify`, `Refund` (default path) | **What is still captured** — captured minus refunded. |
| `state` | `Sync`, `Notify` | Quickpay's own payment state. |
| `capture_amount`, `refund_amount` | *you* | Optional partial-operation amounts; see below. |

`quickpayPaymentId` is camelCase while everything else is snake_case. That is deliberate and it stays
that way: the key is persisted with every payment your shop has ever taken, and its **absence** is
meaningful — the actions read it (missing, or `null`) as "this payment does not exist at Quickpay yet",
so `GetStatus` answers `new` and `Sync` does nothing. Renaming it would
make historical payments report as `new` and could have a capture create a second payment at Quickpay,
silently, for every row a migration missed. Not worth it for a naming preference.

`balance` is the number to read when you need to know how much money is actually held: Payum's status
marks cannot express a partial capture or refund. It is refreshed for free by any action that already
fetches the payment, so you rarely need to ask Quickpay yourself.

To refresh it deliberately, execute Payum's `Sync`:

```php
$quickpay->execute(new Sync($model));   // updates `balance` and `state`
```

### Partial captures and refunds

A capture with no explicit amount is issued for the full `amount` in the details. **A refund with no
explicit amount is issued for the `balance`** — whatever is still refundable — because defaulting to
the full amount would be rejected outright once anything had already been refunded, including refunds
made directly in the Quickpay manager. If nothing is refundable, it throws a `LogicException` saying so
rather than letting Quickpay return a generic validation error.

Payum's `Capture` and `Refund` requests carry no amount of their own, so a partial operation is
expressed by setting an override key on the details first:

```php
$model['refund_amount'] = 250;          // minor units, like every amount here
$quickpay->execute(new Refund($model));

$model['capture_amount'] = 250;
$quickpay->execute(new Capture($model));
```

The keys are per operation and are read only when present — leave them unset for the default described
above. An explicit `refund_amount` also skips the balance fetch, so a partial refund costs no extra
API call. The
gateway **consumes the key once the API has accepted the operation**, so the next capture or refund is
for the full amount again and a stale key cannot silently make it partial. A failed operation keeps its
key, so a retry still refunds what you asked for.

Set the key freshly for each partial operation rather than relying on a previous one: re-executing a
*successful* partial refund against a reloaded payment would fall back to the full `amount`.

The keys apply to captures and refunds issued **through the API** only. The interactive `Capture` — a
fresh payment sent through the window — creates the link for the full `amount` and Quickpay's own
capture takes that; a `capture_amount` set at that point is ignored. All three amounts (`amount`,
`capture_amount`, `refund_amount`) are read strictly: integers or integer strings, in minor units — a
fractional value throws rather than being truncated.

**Both can be repeated.** Quickpay accepts several captures against one authorization, so an order can
be captured in instalments as it ships, and several refunds against what has been captured. Verified
live (2026-08) — authorize 1000, capture 250, capture 250, refund 250, refund 250, ending at a zero
balance. Multi-capture is acquirer-dependent in card processing generally, so confirm it with yours
before designing around it.

The status follows the **balance**, not the last operation:

| After | Balance | `GetStatus` |
|---|---|---|
| capture 250 of 1000 | 250 | `captured` |
| a second capture of 250 | 500 | `captured` |
| refund 250 | 250 | `captured` — money is still held |
| refund the last 250 | 0 | `refunded` |

So `captured` means "something is held", never how much — Payum has no "partially refunded" mark. Read
the `balance` details key for the actual figure rather than inferring it from the mark.

## Sylius

The supported way to use this gateway in a Sylius shop is
[`setono/sylius-quickpay-plugin`](https://github.com/Setono/SyliusQuickpayPlugin), which registers the
factory, adds the admin form for the options in the table above (its *capture mode* is Sylius core's
`use_authorize` option: *immediately* runs the checkout through `Capture`, *on completion* through
`Authorize` and captures when the payment is completed), provides the callback endpoint — including
the account-wide one that resolves the payment by `order_id` — and hooks capture/refund/cancel into the
payment state machine. What follows is the bare wiring, for a shop that does not use the plugin.

Register the gateway factory with PayumBundle under the name `quickpay`:

```yaml
# config/services.yaml
services:
    app.payum.quickpay_gateway_factory:
        class: Payum\Core\Bridge\Symfony\Builder\GatewayFactoryBuilder
        arguments: [Setono\Payum\Quickpay\QuickpayGatewayFactory]
        tags:
            - { name: payum.gateway_factory_builder, factory: quickpay }
```

Then create a payment method with this gateway in the Sylius admin. The stored configuration is
keyed by exactly the option names in the table above (the 1.x spellings `apikey`, `privatekey` and
`agreement` keep working for configs stored before 2.0).

Sylius drives its checkout through `Capture` by default, which is the common flow here (see above):
the payment window captures at authorization and the payment completes. Nothing to configure. If you
want to **authorize only** at checkout and capture later — when the order ships, say — set
`use_authorize: true` in the gateway configuration so Sylius executes `Authorize` instead, and issue
the `Capture` yourself when the time comes.

The callback urls (see Callbacks above) apply unchanged: the payment-window callback routes itself
via the notify token, and an account-wide callback endpoint — if you rely on operation
confirmations — needs to resolve the payment by `order_id` and execute `Notify` on it.

## What this package does not do

The gateway drives Quickpay's **hosted payment window** only — deliberately, since that keeps card
data out of your application (SAQ-A). Not covered: API/card-data authorize, Quickpay
**subscriptions** (recurring payments) and **payouts** — the underlying SDK does not model those
endpoints yet. If you need one of them, open an issue.

[ico-version]: https://img.shields.io/packagist/v/setono/payum-quickpay.svg?include_prereleases&style=flat-square
[ico-license]: https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square
[ico-github-actions]: https://github.com/Setono/payum-quickpay/actions/workflows/build.yaml/badge.svg?branch=2.x
[ico-code-coverage]: https://codecov.io/gh/Setono/payum-quickpay/branch/2.x/graph/badge.svg
[ico-infection]: https://img.shields.io/endpoint?style=flat-square&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2FSetono%2Fpayum-quickpay%2F2.x

[link-packagist]: https://packagist.org/packages/setono/payum-quickpay
[link-github-actions]: https://github.com/Setono/payum-quickpay/actions/workflows/build.yaml?query=branch%3A2.x
[link-code-coverage]: https://codecov.io/gh/Setono/payum-quickpay/branch/2.x
[link-infection]: https://dashboard.stryker-mutator.io/reports/github.com/Setono/payum-quickpay/2.x
