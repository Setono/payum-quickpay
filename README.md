# Payum QuickPay Gateway

[![Build Status](https://travis-ci.org/Setono/payum-quickpay.svg?branch=master)](https://travis-ci.org/Setono/payum-quickpay)

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
