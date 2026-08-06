<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

return (new Configuration())
    // payum/core's CoreGatewayFactory eagerly builds its (deprecated) httplug stack during gateway
    // construction — httplug.message_factory / httplug.stream_factory are resolved through
    // php-http's MessageFactoryDiscovery, which needs the php-http/message-factory interface package.
    // Our own code never references it, but it must stay declared so a gateway can be constructed
    // standalone (the SDK itself uses PSR-17/PSR-18 and does not need it).
    ->ignoreErrorsOnPackage('php-http/message-factory', [ErrorType::UNUSED_DEPENDENCY]);
