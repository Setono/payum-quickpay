<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Tests;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\CoreGatewayFactory;
use Payum\Core\Exception\LogicException;
use Payum\Core\Extension\ExtensionCollection;
use Payum\Core\Gateway;
use Payum\Core\GatewayFactory;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;
use Setono\Payum\Quickpay\Api;
use Setono\Payum\Quickpay\QuickpayGatewayFactory;
use Setono\Quickpay\Client\Client;
use stdClass;

class QuickpayGatewayFactoryTest extends TestCase
{
    /**
     * @test
     *
     * @throws ReflectionException
     */
    public function shouldSubClassGatewayFactory(): void
    {
        $rc = new ReflectionClass(QuickpayGatewayFactory::class);
        self::assertTrue($rc->isSubclassOf(GatewayFactory::class));
    }

    /**
     * @test
     */
    public function couldBeConstructedWithoutAnyArguments(): void
    {
        $factory = new QuickpayGatewayFactory();
        self::assertInstanceOf(QuickpayGatewayFactory::class, $factory);
    }

    /**
     * @test
     */
    public function shouldCreateCoreGatewayFactoryIfNotPassed(): void
    {
        $factory = new QuickpayGatewayFactory();
        self::assertInstanceOf(CoreGatewayFactory::class, self::readProperty($factory, 'coreGatewayFactory'));
    }

    /**
     * @test
     */
    public function shouldAllowCreateGateway(): void
    {
        $factory = new QuickpayGatewayFactory();
        $gateway = $factory->create([
            'apikey' => '1234',
            'privatekey' => '1234',
        ]);
        self::assertInstanceOf(Gateway::class, $gateway);
        self::assertNotEmpty(self::readProperty($gateway, 'apis'));
        self::assertNotEmpty(self::readProperty($gateway, 'actions'));

        $extensions = self::readProperty($gateway, 'extensions');
        self::assertInstanceOf(ExtensionCollection::class, $extensions);
        self::assertNotEmpty(self::readProperty($extensions, 'extensions'));
    }

    /**
     * @test
     */
    public function shouldBuildApiWithInjectedClient(): void
    {
        $client = new Client('injected-key');

        $factory = new QuickpayGatewayFactory();
        $config = $factory->createConfig([
            'apikey' => '1234',
            'privatekey' => 'private',
            'quickpay.client' => $client,
            'order_prefix' => 'sylius-',
        ]);

        self::assertArrayHasKey('payum.api', $config);
        self::assertIsCallable($config['payum.api']);

        $api = $config['payum.api'](ArrayObject::ensureArrayObject($config));

        self::assertInstanceOf(Api::class, $api);
        self::assertSame($client, $api->getClient());
        self::assertSame('sylius-', $api->getOrderPrefix());
    }

    /**
     * @test
     */
    public function shouldBuildClientWithTheConfiguredSynchronizedFlag(): void
    {
        $factory = new QuickpayGatewayFactory();

        self::assertFalse(self::createApi($factory, [])->isSynchronized());
        self::assertTrue(self::createApi($factory, ['synchronized' => true])->isSynchronized());
    }

    /**
     * @test
     */
    public function shouldThrowWhenInjectedClientDisagreesOnSynchronized(): void
    {
        $factory = new QuickpayGatewayFactory();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('is not synchronized');

        self::createApi($factory, [
            'quickpay.client' => new Client('injected-key'),
            'synchronized' => true,
        ]);
    }

    /**
     * @test
     */
    public function shouldAcceptPaymentMethodsAsAStringOrAList(): void
    {
        $factory = new QuickpayGatewayFactory();

        self::assertNull(self::createApi($factory, [])->getPaymentMethods());
        self::assertSame(
            'creditcard,!jcb',
            self::createApi($factory, ['payment_methods' => 'creditcard,!jcb'])->getPaymentMethods(),
        );
        self::assertSame(
            'creditcard,!jcb',
            self::createApi($factory, ['payment_methods' => ['creditcard', ' !jcb ', '']])->getPaymentMethods(),
        );
        self::assertNull(self::createApi($factory, ['payment_methods' => []])->getPaymentMethods());
    }

    /**
     * @test
     *
     * @dataProvider invalidPaymentMethodsProvider
     */
    public function shouldThrowWhenPaymentMethodsIsNeitherStringNorListOfStrings(mixed $paymentMethods): void
    {
        $factory = new QuickpayGatewayFactory();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must be a string or a list of strings');

        self::createApi($factory, ['payment_methods' => $paymentMethods]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidPaymentMethodsProvider(): iterable
    {
        yield 'object' => [new stdClass()];
        yield 'int' => [42];
        yield 'list containing a non-string' => [['creditcard', 42]];
        yield 'list containing an object' => [['creditcard', new stdClass()]];
    }

    /**
     * @test
     */
    public function shouldThrowWhenInjectedClientIsInvalid(): void
    {
        $factory = new QuickpayGatewayFactory();
        $config = $factory->createConfig([
            'apikey' => '1234',
            'privatekey' => 'private',
            'quickpay.client' => new stdClass(),
        ]);

        $this->expectException(LogicException::class);

        $config['payum.api'](ArrayObject::ensureArrayObject($config));
    }

    /**
     * @test
     */
    public function shouldAllowCreateGatewayConfig(): void
    {
        $factory = new QuickpayGatewayFactory();
        $config = $factory->createConfig();
        self::assertIsArray($config);
        self::assertNotEmpty($config);
    }

    /**
     * @test
     */
    public function shouldConfigContainFactoryNameAndTitle(): void
    {
        $factory = new QuickpayGatewayFactory();
        $config = $factory->createConfig();
        self::assertIsArray($config);
        self::assertArrayHasKey('payum.factory_name', $config);
        self::assertEquals('quickpay', $config['payum.factory_name']);
        self::assertArrayHasKey('payum.factory_title', $config);
        self::assertEquals('Quickpay', $config['payum.factory_title']);
    }

    /**
     * Builds the {@see Api} that the "payum.api" factory closure produces for the given gateway
     * options, on top of the two required ones.
     *
     * @param array<string, mixed> $options
     */
    private static function createApi(QuickpayGatewayFactory $factory, array $options): Api
    {
        $config = $factory->createConfig(array_replace([
            'apikey' => '1234',
            'privatekey' => 'private',
        ], $options));

        $api = $config['payum.api'](ArrayObject::ensureArrayObject($config));
        self::assertInstanceOf(Api::class, $api);

        return $api;
    }

    /**
     * Reads a non-public property off an object, replacing the assertAttribute and readAttribute
     * helpers that were removed in PHPUnit 9.
     */
    private static function readProperty(object $object, string $property): mixed
    {
        return (new ReflectionProperty($object, $property))->getValue($object);
    }
}
