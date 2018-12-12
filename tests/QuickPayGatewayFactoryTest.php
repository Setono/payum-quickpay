<?php

namespace Setono\Payum\QuickPay\Tests;

use Setono\Payum\QuickPay\QuickPayGatewayFactory;
use PHPUnit\Framework\TestCase;

/**
 * @author jdk
 */
class QuickPayGatewayFactoryTest extends TestCase
{
    /**
     * @test
     * @throws \ReflectionException
     */
    public function shouldSubClassGatewayFactory()
    {
        $rc = new \ReflectionClass(QuickPayGatewayFactory::class);
        $this->assertTrue($rc->isSubclassOf('Payum\Core\GatewayFactory'));
    }

    /**
     * @test
     */
    public function couldBeConstructedWithoutAnyArguments()
    {
        $factory = new QuickPayGatewayFactory();
        $this->assertInstanceOf(QuickPayGatewayFactory::class, $factory);
    }

    /**
     * @test
     */
    public function shouldCreateCoreGatewayFactoryIfNotPassed()
    {
        $factory = new QuickPayGatewayFactory();
        $this->assertAttributeInstanceOf('Payum\Core\CoreGatewayFactory', 'coreGatewayFactory', $factory);
    }

    /**
     * @test
     */
    public function shouldAllowCreateGateway()
    {
        $factory = new QuickPayGatewayFactory();
        $gateway = $factory->create([
            'apikey' => '1234',
            'privatekey' => '1234',
            'merchant' => '1234',
            'agreement' => '1234',
        ]);
        $this->assertInstanceOf('Payum\Core\Gateway', $gateway);
        $this->assertAttributeNotEmpty('apis', $gateway);
        $this->assertAttributeNotEmpty('actions', $gateway);
        $extensions = $this->readAttribute($gateway, 'extensions');
        $this->assertAttributeNotEmpty('extensions', $extensions);
    }

    /**
     * @test
     */
    public function shouldAllowCreateGatewayConfig()
    {
        $factory = new QuickPayGatewayFactory();
        $config = $factory->createConfig();
        $this->assertInternalType('array', $config);
        $this->assertNotEmpty($config);
    }

    /**
     * @test
     */
    public function shouldConfigContainFactoryNameAndTitle()
    {
        $factory = new QuickPayGatewayFactory();
        $config = $factory->createConfig();
        $this->assertInternalType('array', $config);
        $this->assertArrayHasKey('payum.factory_name', $config);
        $this->assertEquals('quickpay', $config['payum.factory_name']);
        $this->assertArrayHasKey('payum.factory_title', $config);
        $this->assertEquals('QuickPay', $config['payum.factory_title']);
    }
}
