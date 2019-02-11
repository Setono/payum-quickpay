# Payum QuickPay Gateway

[![Latest Version on Packagist][ico-version]][link-packagist]
[![Software License][ico-license]](LICENSE)
[![Build Status][ico-travis]][link-travis]
[![Quality Score][ico-code-quality]][link-code-quality]

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


## Contributors
- [Jais Djurhuus-Kempel](https://github.com/JaisDK)

[ico-version]: https://img.shields.io/packagist/v/setono/payum-quickpay.svg?style=flat-square
[ico-license]: https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square
[ico-travis]: https://travis-ci.com/Setono/payum-quickpay.svg?branch=master
[ico-code-quality]: https://img.shields.io/scrutinizer/g/Setono/payum-quickpay.svg?style=flat-square

[link-packagist]: https://packagist.org/packages/setono/payum-quickpay
[link-travis]: https://travis-ci.com/Setono/payum-quickpay
[link-code-quality]: https://scrutinizer-ci.com/g/Setono/payum-quickpay
