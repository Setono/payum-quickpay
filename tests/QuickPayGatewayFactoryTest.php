<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Tests;

use Payum\Core\CoreGatewayFactory;
use Payum\Core\Gateway;
use Payum\Core\GatewayFactory;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use Setono\Payum\QuickPay\QuickPayGatewayFactory;

class QuickPayGatewayFactoryTest extends TestCase
{
    /**
     * @test
     *
     * @throws ReflectionException
     */
    public function shouldSubClassGatewayFactory(): void
    {
        $rc = new ReflectionClass(QuickPayGatewayFactory::class);
        self::assertTrue($rc->isSubclassOf(GatewayFactory::class));
    }

    /**
     * @test
     */
    public function couldBeConstructedWithoutAnyArguments(): void
    {
        $factory = new QuickPayGatewayFactory();
        self::assertInstanceOf(QuickPayGatewayFactory::class, $factory);
    }

    /**
     * @test
     */
    public function shouldCreateCoreGatewayFactoryIfNotPassed(): void
    {
        $factory = new QuickPayGatewayFactory();
        self::assertAttributeInstanceOf(CoreGatewayFactory::class, 'coreGatewayFactory', $factory);
    }

    /**
     * @test
     */
    public function shouldAllowCreateGateway(): void
    {
        $factory = new QuickPayGatewayFactory();
        $gateway = $factory->create([
            'apikey' => '1234',
            'privatekey' => '1234',
            'merchant' => '1234',
            'agreement' => '1234',
        ]);
        self::assertInstanceOf(Gateway::class, $gateway);
        self::assertAttributeNotEmpty('apis', $gateway);
        self::assertAttributeNotEmpty('actions', $gateway);
        $extensions = $this->readAttribute($gateway, 'extensions');
        self::assertAttributeNotEmpty('extensions', $extensions);
    }

    /**
     * @test
     */
    public function shouldAllowCreateGatewayConfig(): void
    {
        $factory = new QuickPayGatewayFactory();
        $config = $factory->createConfig();
        self::assertInternalType('array', $config);
        self::assertNotEmpty($config);
    }

    /**
     * @test
     */
    public function shouldConfigContainFactoryNameAndTitle(): void
    {
        $factory = new QuickPayGatewayFactory();
        $config = $factory->createConfig();
        self::assertInternalType('array', $config);
        self::assertArrayHasKey('payum.factory_name', $config);
        self::assertEquals('quickpay', $config['payum.factory_name']);
        self::assertArrayHasKey('payum.factory_title', $config);
        self::assertEquals('QuickPay', $config['payum.factory_title']);
    }
}
