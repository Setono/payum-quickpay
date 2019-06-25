<?php

declare(strict_types=1);

if (!$loader = @include __DIR__.'/../vendor/autoload.php') {
    echo <<<EOM
You must set up the project dependencies by running the following commands:
    curl -s http://getcomposer.org/installer | php
    php composer.phar install --dev
EOM;
    exit(1);
}

use Payum\Core\GatewayInterface;

$rc = new ReflectionClass(GatewayInterface::class);
$coreDir = dirname($rc->getFileName()).'/tests';
$loader->add('Payum\Core\Tests', $coreDir);
$loader->add('Setono\Payum\QuickPay\Tests', $coreDir);
