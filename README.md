# Payum Quickpay Gateway

[![Latest Version on Packagist][ico-version]][link-packagist]
[![Software License][ico-license]](LICENSE)
[![Build Status][ico-github-actions]][link-github-actions]
[![Code Coverage][ico-code-coverage]][link-code-coverage]

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

> **Note:** the SDK is currently released as `1.0.0-beta.1`. Until a stable release is tagged, your
> project needs `"minimum-stability": "beta"` (with `"prefer-stable": true`) for Composer to resolve it.

## Configuration

The gateway requires your Quickpay **API key** (Settings → API user) and **private key** (Settings →
Integration — used to verify callback signatures). Other options are optional:

| Option            | Default | Description                                                          |
|-------------------|---------|----------------------------------------------------------------------|
| `api_key`         | —       | **Required.** Quickpay API key.                                      |
| `private_key`     | —       | **Required.** Private key; used to verify the callback HMAC.         |
| `auto_capture`    | `0`     | Capture automatically once an approved authorize is confirmed.       |
| `payment_methods` | `''`    | Restrict the payment-window methods (e.g. `creditcard`). See below.  |
| `order_prefix`    | `''`    | Prepended to the Payum payment number to form the Quickpay order id. |
| `language`        | `en`    | Payment-window language.                                             |
| `synchronized`    | `false` | Run capture/refund/cancel synchronously instead of via callbacks.    |
| `agreement`       | `''`    | Optional payment-window agreement id.                                |
| `branding_id`     | `''`    | Optional payment-window branding id.                                 |

The 1.x spellings `apikey` and `privatekey` are still accepted as deprecated aliases, so an existing
gateway configuration keeps working. They will be removed in 3.0.

```php
<?php

use Payum\Core\PayumBuilder;
use Payum\Core\GatewayFactoryInterface;

$defaultConfig = [];

$payum = (new PayumBuilder)
    ->addGatewayFactory('quickpay', function(array $config, GatewayFactoryInterface $coreGatewayFactory) {
        return new \Setono\Payum\Quickpay\QuickpayGatewayFactory($config, $coreGatewayFactory);
    })
    ->addGateway('quickpay', [
        'factory' => 'quickpay'
    ])
    ->getPayum();
```

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

```php
<?php

use Payum\Core\Request\Capture;

$quickpay = $payum->getGateway('quickpay');

$model = new \ArrayObject([
  // ...
]);

$quickpay->execute(new Capture($model));
```

### The payment details

The gateway stores **only scalars** in the details, so they survive whatever serialization your storage
uses. `quickpayPaymentId` is the single source of truth — everything else is a snapshot.

| Key | Written by | Meaning |
|-----|------------|---------|
| `quickpayPaymentId` | `Convert` | The Quickpay payment id. Everything else is re-fetched with it. |
| `amount`, `currency` | `Convert` | The payment total, in minor units. |
| `order_id` | `Convert` | `order_prefix` + the Payum payment number. |
| `continue_url`, `cancel_url` | `Convert` | From the token's after-URL. |
| `callback_url` | `Authorize` | The notify token url given to Quickpay. |
| `balance` | `GetStatus`, `Sync`, `Notify` | **What is still captured** — captured minus refunded. |
| `state` | `Sync` | Quickpay's own payment state. |
| `capture_amount`, `refund_amount` | *you* | Optional partial-operation amounts; see below. |

`quickpayPaymentId` is camelCase while everything else is snake_case. That is deliberate and it stays
that way: the key is persisted with every payment your shop has ever taken, and its **absence** is
meaningful — the actions read it as "this payment does not exist at Quickpay yet". Renaming it would
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

[ico-version]: https://img.shields.io/packagist/v/setono/payum-quickpay.svg?include_prereleases&style=flat-square
[ico-license]: https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square
[ico-github-actions]: https://github.com/Setono/payum-quickpay/actions/workflows/build.yaml/badge.svg?branch=2.x
[ico-code-coverage]: https://codecov.io/gh/Setono/payum-quickpay/branch/2.x/graph/badge.svg

[link-packagist]: https://packagist.org/packages/setono/payum-quickpay
[link-github-actions]: https://github.com/Setono/payum-quickpay/actions/workflows/build.yaml?query=branch%3A2.x
[link-code-coverage]: https://codecov.io/gh/Setono/payum-quickpay/branch/2.x
