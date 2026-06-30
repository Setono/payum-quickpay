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

> **Note:** the SDK is currently released as `1.0.0-alpha.1`. Until a stable release is tagged, your
> project needs `"minimum-stability": "alpha"` (with `"prefer-stable": true`) for Composer to resolve it.

## Configuration

The gateway requires your Quickpay **API key** (Settings → API user) and **private key** (Settings →
Integration — used to verify callback signatures). Other options are optional:

| Option            | Default | Description                                                          |
|-------------------|---------|----------------------------------------------------------------------|
| `apikey`          | —       | **Required.** Quickpay API key.                                      |
| `privatekey`      | —       | **Required.** Private key; used to verify the callback HMAC.         |
| `auto_capture`    | `0`     | Capture automatically once an approved authorize is confirmed.       |
| `payment_methods` | `''`    | Restrict the payment-window methods (e.g. `creditcard`).             |
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

[ico-version]: https://img.shields.io/packagist/v/setono/payum-quickpay.svg?style=flat-square
[ico-license]: https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square
[ico-github-actions]: https://github.com/Setono/payum-quickpay/workflows/build/badge.svg
[ico-code-coverage]: https://codecov.io/gh/Setono/payum-quickpay/branch/1.x/graph/badge.svg

[link-packagist]: https://packagist.org/packages/setono/payum-quickpay
[link-github-actions]: https://github.com/Setono/payum-quickpay/actions
[link-code-coverage]: https://codecov.io/gh/Setono/payum-quickpay
