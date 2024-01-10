# Payum QuickPay Gateway

[![Latest Version on Packagist][ico-version]][link-packagist]
[![Software License][ico-license]](LICENSE)
[![Build Status][ico-github-actions]][link-github-actions]
[![Code Coverage][ico-code-coverage]][link-code-coverage]

This component enables the use of QuickPay with Payum.

## Installation

``composer require setono/payum-quickpay``

## Configuration

```php
<?php

use Payum\Core\PayumBuilder;
use Payum\Core\GatewayFactoryInterface;

$defaultConfig = [];

$payum = (new PayumBuilder)
    ->addGatewayFactory('quickpay', function(array $config, GatewayFactoryInterface $coreGatewayFactory) {
        return new \Setono\Payum\QuickPay\QuickPayGatewayFactory($config, $coreGatewayFactory);
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
