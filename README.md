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
| `apikey`          | —       | **Required.** Quickpay API key.                                      |
| `privatekey`      | —       | **Required.** Private key; used to verify the callback HMAC.         |
| `auto_capture`    | `0`     | Capture automatically once an approved authorize is confirmed.       |
| `payment_methods` | `''`    | Restrict the payment-window methods (e.g. `creditcard`). See below.  |
| `order_prefix`    | `''`    | Prepended to the Payum payment number to form the Quickpay order id. |
| `language`        | `en`    | Payment-window language.                                             |
| `synchronized`    | `false` | Run capture/refund/cancel synchronously instead of via callbacks.    |
| `agreement`       | `''`    | Optional payment-window agreement id.                                |
| `branding_id`     | `''`    | Optional payment-window branding id.                                 |

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

### Partial captures and refunds

By default a capture or refund is issued for the full `amount` in the details. Payum's `Capture` and
`Refund` requests carry no amount of their own, so a partial operation is expressed by setting an
override key on the details first:

```php
$model['refund_amount'] = 250;          // minor units, like every amount here
$quickpay->execute(new Refund($model));

$model['capture_amount'] = 250;
$quickpay->execute(new Capture($model));
```

The keys are per operation and are read only when present — leave them unset for the full amount. The
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

So `captured` means "something is held", not "all of it". Read `balance` from Quickpay when you need
the actual figure.

Note what the status becomes afterwards. Payum has no "partially refunded" mark, so `GetStatus` reports
a payment with an outstanding balance as **`captured`**, and only reports `refunded` once the balance
reaches zero. Read the remaining amount from Quickpay (the payment's `balance`) rather than inferring it
from the Payum mark. https://img.shields.io/packagist/v/setono/payum-quickpay.svg?style=flat-square
[ico-license]: https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square
[ico-github-actions]: https://github.com/Setono/payum-quickpay/workflows/build/badge.svg
[ico-code-coverage]: https://codecov.io/gh/Setono/payum-quickpay/branch/1.x/graph/badge.svg

[link-packagist]: https://packagist.org/packages/setono/payum-quickpay
[link-github-actions]: https://github.com/Setono/payum-quickpay/actions
[link-code-coverage]: https://codecov.io/gh/Setono/payum-quickpay
