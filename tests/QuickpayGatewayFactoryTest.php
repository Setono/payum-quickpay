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
            'api_key' => '1234',
            'private_key' => '1234',
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
            'api_key' => '1234',
            'private_key' => 'private',
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

        self::assertSame(
            'creditcard,!jcb',
            self::createApi($factory, ['payment_methods' => 'creditcard,!jcb'])->getPaymentMethods(),
        );
        self::assertSame(
            'creditcard,!jcb',
            self::createApi($factory, ['payment_methods' => ['creditcard', ' !jcb ', '']])->getPaymentMethods(),
        );
    }

    /**
     * Empty configuration must reach the Api as null, not as the empty string it is written as in the
     * gateway options — null is what keeps `payment_methods` off the request entirely.
     *
     * @test
     *
     * @dataProvider emptyPaymentMethodsProvider
     */
    public function shouldNormalizeEmptyPaymentMethodsToNull(mixed $paymentMethods): void
    {
        $factory = new QuickpayGatewayFactory();

        self::assertNull(self::createApi($factory, ['payment_methods' => $paymentMethods])->getPaymentMethods());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function emptyPaymentMethodsProvider(): iterable
    {
        yield 'the default empty string' => [''];
        yield 'a blank string' => ['   '];
        yield 'an empty list' => [[]];
        yield 'a list of blanks' => [['', ' ']];
    }

    /**
     * @test
     */
    public function shouldDefaultPaymentMethodsToNull(): void
    {
        self::assertNull(self::createApi(new QuickpayGatewayFactory(), [])->getPaymentMethods());
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
     * A stored or YAML-sourced gateway config easily stringifies booleans, and the old casts read
     * the string "true" as FALSE ((int) "true" is 0) and "false" as TRUE — an inverted setting with
     * nothing in the configuration that looks wrong. Pin every unambiguous spelling.
     *
     * @test
     *
     * @dataProvider booleanOptionProvider
     */
    public function shouldNormalizeTheBooleanOptions(mixed $value, bool $expected): void
    {
        $api = self::createApi(new QuickpayGatewayFactory(), [
            'auto_capture' => $value,
            'synchronized' => $value,
        ]);

        self::assertSame($expected, $api->isAutoCapture());
        self::assertSame($expected, $api->isSynchronized());
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function booleanOptionProvider(): iterable
    {
        yield 'true' => [true, true];
        yield 'false' => [false, false];
        yield 'int 1' => [1, true];
        yield 'int 0' => [0, false];
        yield 'string 1' => ['1', true];
        yield 'string 0' => ['0', false];
        yield 'string true' => ['true', true];
        yield 'string false' => ['false', false];
        yield 'empty string (the Payum config artifact)' => ['', false];
        yield 'null' => [null, false];
    }

    /**
     * @test
     *
     * @dataProvider ambiguousBooleanProvider
     */
    public function shouldThrowWhenABooleanOptionIsAmbiguous(mixed $value): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must be a boolean');

        self::createApi(new QuickpayGatewayFactory(), ['auto_capture' => $value]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function ambiguousBooleanProvider(): iterable
    {
        yield 'arbitrary string' => ['yolo'];
        yield 'int other than 0/1' => [2];
        yield 'array' => [[true]];
        yield 'object' => [new stdClass()];
    }

    /**
     * @test
     *
     * @dataProvider idOptionProvider
     */
    public function shouldNormalizeTheIdOptions(mixed $value, ?int $expected): void
    {
        $api = self::createApi(new QuickpayGatewayFactory(), [
            'agreement_id' => $value,
            'branding_id' => $value,
        ]);

        self::assertSame($expected, $api->getAgreementId());
        self::assertSame($expected, $api->getBrandingId());
    }

    /**
     * @return iterable<string, array{mixed, int|null}>
     */
    public static function idOptionProvider(): iterable
    {
        yield 'int' => [266017, 266017];
        yield 'integer string' => ['266017', 266017];
        yield 'integer string with whitespace' => [' 266017 ', 266017];
        yield 'empty string (the Payum config artifact)' => ['', null];
        yield 'blank string' => ['   ', null];
        yield 'null' => [null, null];
    }

    /**
     * The old `(int)` cast turned a typo like "abc" into agreement id 0 and sent that to Quickpay.
     *
     * @test
     *
     * @dataProvider invalidIdProvider
     */
    public function shouldThrowWhenAnIdOptionIsInvalid(mixed $value): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must be a positive integer');

        self::createApi(new QuickpayGatewayFactory(), ['agreement_id' => $value]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidIdProvider(): iterable
    {
        yield 'not a number' => ['abc'];
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'float' => [10.5];
        yield 'array' => [[266017]];
    }

    /**
     * @test
     */
    public function shouldThrowWhenInjectedClientIsInvalid(): void
    {
        $factory = new QuickpayGatewayFactory();
        $config = $factory->createConfig([
            'api_key' => '1234',
            'private_key' => 'private',
            'quickpay.client' => new stdClass(),
        ]);

        $this->expectException(LogicException::class);

        $config['payum.api'](ArrayObject::ensureArrayObject($config));
    }

    /**
     * The credentials were `apikey` / `privatekey` in 1.x. They are required, so every consumer sets
     * them — and Sylius stores the gateway configuration keyed by exactly these names, so dropping the
     * old spellings would break every existing shop until its stored config was migrated.
     *
     * @test
     */
    public function shouldAcceptTheDeprecatedCredentialOptionNames(): void
    {
        $factory = new QuickpayGatewayFactory();

        $config = $factory->createConfig([
            'apikey' => 'old-api-key',
            'privatekey' => 'old-private-key',
            'agreement' => '266017',
        ]);

        $api = $config['payum.api'](ArrayObject::ensureArrayObject($config));

        self::assertInstanceOf(Api::class, $api);
        self::assertSame('old-private-key', $api->getPrivateKey());
        self::assertSame(266017, $api->getAgreementId());
    }

    /**
     * `agreement` is optional, so a name that silently stopped being read would not throw — the payment
     * link would just be created without an agreement id, falling back to the account default. Pin both
     * directions.
     *
     * @test
     */
    public function shouldReadTheAgreementIdUnderEitherName(): void
    {
        $factory = new QuickpayGatewayFactory();

        self::assertSame(266017, self::createApi($factory, ['agreement_id' => '266017'])->getAgreementId());
        self::assertSame(266017, self::createApi($factory, ['agreement' => '266017'])->getAgreementId());
        self::assertNull(self::createApi($factory, [])->getAgreementId(), 'Unset stays unset');
        self::assertSame(
            266017,
            self::createApi($factory, ['agreement' => '1', 'agreement_id' => '266017'])->getAgreementId(),
            'The current name wins',
        );
    }

    /**
     * @test
     */
    public function shouldPreferTheCurrentNamesWhenBothAreGiven(): void
    {
        $factory = new QuickpayGatewayFactory();

        $config = $factory->createConfig([
            'apikey' => 'old-api-key',
            'privatekey' => 'old-private-key',
            'api_key' => 'current-api-key',
            'private_key' => 'current-private-key',
        ]);

        $api = $config['payum.api'](ArrayObject::ensureArrayObject($config));

        self::assertSame('current-private-key', $api->getPrivateKey());
    }

    /**
     * The deprecated names must satisfy the required-options validation too — otherwise a 1.x config
     * would fail before ever reaching the alias.
     *
     * @test
     */
    public function shouldStillRequireCredentialsUnderEitherSpelling(): void
    {
        $factory = new QuickpayGatewayFactory();

        $config = $factory->createConfig(['apikey' => 'only-the-api-key']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('private_key');

        $config['payum.api'](ArrayObject::ensureArrayObject($config));
    }

    /**
     * @test
     */
    public function shouldRegisterAnActionForEveryRequestTheGatewaySupports(): void
    {
        $config = (new QuickpayGatewayFactory())->createConfig();

        foreach ([
            'payum.action.capture',
            'payum.action.authorize',
            'payum.action.refund',
            'payum.action.cancel',
            'payum.action.notify',
            'payum.action.status',
            'payum.action.sync',
            'payum.action.convert_payment',
            'payum.action.api.confirm_payment',
            'payum.action.api.create_payment_link',
        ] as $key) {
            self::assertArrayHasKey($key, $config, sprintf('Missing %s', $key));
        }
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
        self::assertSame(QuickpayGatewayFactory::NAME, $config['payum.factory_name']);
        self::assertArrayHasKey('payum.factory_title', $config);
        self::assertEquals('Quickpay', $config['payum.factory_title']);
    }

    /**
     * The literal is asserted here rather than only through the constant, because the value — not the
     * symbol — is the contract: consumers store it as `factoryName` on their gateway configurations, so
     * changing it would orphan every one of them. This test is what makes that a deliberate decision
     * instead of a rename nobody noticed.
     *
     * @test
     */
    public function shouldExposeTheFactoryNameAsAConstant(): void
    {
        self::assertSame('quickpay', QuickpayGatewayFactory::NAME);
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
            'api_key' => '1234',
            'private_key' => 'private',
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
