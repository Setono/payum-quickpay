<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Tests;

use Http\Discovery\Psr18ClientDiscovery;
use LogicException;
use Payum\Core\Exception\LogicException as PayumLogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Setono\Payum\Quickpay\Exception\InvalidGatewayOptions;
use Setono\Payum\Quickpay\GatewayOptions;
use Setono\Payum\Quickpay\QuickpayGatewayFactory;
use stdClass;

final class GatewayOptionsTest extends TestCase
{
    #[Test]
    public function shouldApplyTheDefaultsToAConfigWithOnlyTheCredentials(): void
    {
        $options = GatewayOptions::fromConfig(['api_key' => 'the-api-key', 'private_key' => 'the-private-key']);

        self::assertSame('the-api-key', $options->apiKey);
        self::assertSame('the-private-key', $options->privateKey);
        self::assertSame('', $options->orderPrefix);
        self::assertNull($options->paymentMethods);
        self::assertSame('en', $options->language);
        self::assertFalse($options->autoCapture);
        self::assertFalse($options->synchronized);
        self::assertNull($options->agreementId);
        self::assertNull($options->brandingId);
    }

    #[Test]
    #[DataProvider('validIdProvider')]
    public function shouldNormalizeTheIds(mixed $value, ?int $expected): void
    {
        $options = self::fromValidConfig(['agreement_id' => $value, 'branding_id' => $value]);

        self::assertSame($expected, $options->agreementId);
        self::assertSame($expected, $options->brandingId);
    }

    /**
     * @return iterable<string, array{mixed, int|null}>
     */
    public static function validIdProvider(): iterable
    {
        yield 'int' => [266017, 266017];
        yield 'digit string' => ['266017', 266017];
        yield 'digit string with whitespace' => [' 266017 ', 266017];
        yield 'empty string' => ['', null];
        yield 'blank string' => ['   ', null];
        yield 'null' => [null, null];
    }

    /**
     * The old `(int)` cast turned a typo like "abc" into agreement id 0 and sent that; `'1e3'` and
     * `12.0` are numbers PHP would happily coerce, but not ids anyone typed on purpose.
     */
    #[Test]
    #[DataProvider('invalidIdProvider')]
    public function shouldRejectAnIdThatIsNotAPositiveInteger(mixed $value): void
    {
        $violations = self::violationsOf(['agreement_id' => $value, 'branding_id' => $value]);

        self::assertSame(['agreement_id', 'branding_id'], array_keys($violations));
        self::assertStringContainsString('must be a positive integer', $violations['agreement_id']);
        self::assertStringContainsString('"branding_id"', $violations['branding_id']);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidIdProvider(): iterable
    {
        yield 'exponent string' => ['1e3'];
        yield 'zero' => [0];
        yield 'float' => [12.0];
        yield 'negative' => [-1];
        yield 'not a number' => ['abc'];
        yield 'array' => [[266017]];
    }

    /**
     * A stored or YAML-sourced config easily stringifies booleans, and a cast reads `"false"` as true.
     */
    #[Test]
    #[DataProvider('validBooleanProvider')]
    public function shouldNormalizeTheBooleans(mixed $value, bool $expected): void
    {
        $options = self::fromValidConfig(['auto_capture' => $value, 'synchronized' => $value]);

        self::assertSame($expected, $options->autoCapture);
        self::assertSame($expected, $options->synchronized);
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function validBooleanProvider(): iterable
    {
        yield 'true' => [true, true];
        yield 'false' => [false, false];
        yield 'int 1' => [1, true];
        yield 'int 0' => [0, false];
        yield 'string 1' => ['1', true];
        yield 'string 0' => ['0', false];
        yield 'string true' => ['true', true];
        yield 'string false' => ['false', false];
        yield 'string yes' => ['yes', true];
        yield 'string no' => ['no', false];
        yield 'empty string' => ['', false];
        yield 'null' => [null, false];
    }

    #[Test]
    #[DataProvider('invalidBooleanProvider')]
    public function shouldRejectABooleanThatIsAmbiguous(mixed $value): void
    {
        $violations = self::violationsOf(['auto_capture' => $value, 'synchronized' => $value]);

        self::assertSame(['auto_capture', 'synchronized'], array_keys($violations));
        self::assertStringContainsString('"auto_capture" option must be a boolean', $violations['auto_capture']);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidBooleanProvider(): iterable
    {
        yield 'arbitrary string' => ['abc'];
        yield 'int other than 0/1' => [2];
        yield 'array' => [[true]];
        yield 'object' => [new stdClass()];
    }

    #[Test]
    #[DataProvider('validPaymentMethodsProvider')]
    public function shouldNormalizeThePaymentMethodsToQuickpaysCommaSeparatedForm(mixed $value, ?string $expected): void
    {
        self::assertSame($expected, self::fromValidConfig(['payment_methods' => $value])->paymentMethods);
    }

    /**
     * @return iterable<string, array{mixed, string|null}>
     */
    public static function validPaymentMethodsProvider(): iterable
    {
        yield 'a string' => ['creditcard,!jcb', 'creditcard,!jcb'];
        yield 'a string with surrounding whitespace' => [' creditcard ', 'creditcard'];
        yield 'a list' => [['visa', ' mobilepay '], 'visa,mobilepay'];
        yield 'a list with blanks' => [['visa', '', '  ', 'mobilepay'], 'visa,mobilepay'];
        yield 'an empty string' => ['', null];
        yield 'a blank string' => ['   ', null];
        yield 'an empty list' => [[], null];
        yield 'a list of blanks' => [['', ' '], null];
        yield 'null' => [null, null];
    }

    /**
     * Cast with `(string)`, a list would send the literal `Array` — an allowlist of one unknown method.
     */
    #[Test]
    #[DataProvider('invalidPaymentMethodsProvider')]
    public function shouldRejectPaymentMethodsThatAreNeitherAStringNorAListOfStrings(mixed $value): void
    {
        $violations = self::violationsOf(['payment_methods' => $value]);

        self::assertSame(['payment_methods'], array_keys($violations));
        self::assertStringContainsString('must be a string or a list of strings', $violations['payment_methods']);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidPaymentMethodsProvider(): iterable
    {
        yield 'int' => [42];
        yield 'bool' => [true];
        yield 'object' => [new stdClass()];
        yield 'list containing a non-string' => [['creditcard', 42]];
        yield 'list containing a list' => [['creditcard', ['visa']]];
    }

    /**
     * `'0'` is what Payum's own required-options check (`empty()`) has always rejected too.
     *
     * @param array<string, mixed> $credentials
     */
    #[Test]
    #[DataProvider('missingCredentialsProvider')]
    public function shouldRequireTheCredentials(array $credentials): void
    {
        $violations = self::violationsOf($credentials);

        self::assertSame(['api_key', 'private_key'], array_keys($violations));
        self::assertSame('The "api_key" option is required', $violations['api_key']);
        self::assertSame('The "private_key" option is required', $violations['private_key']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function missingCredentialsProvider(): iterable
    {
        yield 'missing' => [['api_key' => null, 'private_key' => null]];
        yield 'empty' => [['api_key' => '', 'private_key' => '']];
        yield 'zero string' => [['api_key' => '0', 'private_key' => '0']];
        yield 'false' => [['api_key' => false, 'private_key' => false]];
    }

    #[Test]
    public function shouldRequireTheCredentialsWhenTheyAreAbsentAltogether(): void
    {
        try {
            GatewayOptions::fromConfig(['order_prefix' => 'shop-']);
            self::fail('The config was accepted');
        } catch (InvalidGatewayOptions $e) {
            self::assertSame(['api_key', 'private_key'], array_keys($e->getViolations()));
        }
    }

    #[Test]
    public function shouldRejectCredentialsThatAreNotStrings(): void
    {
        $violations = self::violationsOf(['api_key' => ['the-api-key'], 'private_key' => new stdClass()]);

        self::assertSame(['api_key', 'private_key'], array_keys($violations));
        self::assertSame('The "api_key" option must be a string, got array', $violations['api_key']);
        self::assertSame('The "private_key" option must be a string, got stdClass', $violations['private_key']);
    }

    #[Test]
    public function shouldReadTheStringOptions(): void
    {
        $options = self::fromValidConfig(['order_prefix' => 'shop-', 'language' => 'da']);

        self::assertSame('shop-', $options->orderPrefix);
        self::assertSame('da', $options->language);
    }

    /**
     * The gateway has always cast these with `(string)`, so a number stored where a string belongs keeps
     * working — readers must see exactly what the gateway sends.
     */
    #[Test]
    public function shouldReadAScalarStringOptionAsAString(): void
    {
        $options = self::fromValidConfig(['order_prefix' => 12, 'language' => null]);

        self::assertSame('12', $options->orderPrefix);
        self::assertSame('en', $options->language, 'Null is unset, so the default applies');
    }

    #[Test]
    public function shouldRejectAStringOptionThatIsNotAScalar(): void
    {
        $violations = self::violationsOf(['order_prefix' => ['shop-'], 'language' => new stdClass()]);

        self::assertSame(['order_prefix', 'language'], array_keys($violations));
        self::assertSame('The "order_prefix" option must be a string, got array', $violations['order_prefix']);
    }

    /**
     * Fix them all in one go rather than one per attempt — and still catchable as before.
     */
    #[Test]
    public function shouldReportEveryViolationAtOnce(): void
    {
        try {
            GatewayOptions::fromConfig([
                'api_key' => '',
                'private_key' => 'the-private-key',
                'payment_methods' => 42,
                'auto_capture' => 'abc',
                'agreement_id' => '1e3',
                'branding_id' => 0,
            ]);
            self::fail('The config was accepted');
        } catch (InvalidGatewayOptions $e) {
            self::assertInstanceOf(LogicException::class, $e);
            self::assertInstanceOf(PayumLogicException::class, $e);
            self::assertEqualsCanonicalizing(
                ['api_key', 'payment_methods', 'auto_capture', 'agreement_id', 'branding_id'],
                array_keys($e->getViolations()),
            );

            foreach ($e->getViolations() as $violation) {
                self::assertStringContainsString($violation, $e->getMessage());
            }
        }
    }

    /**
     * Sylius stores gateway configs keyed by the 1.x names, so they keep working until 3.0.
     */
    #[Test]
    public function shouldReadTheDeprecatedNamesWhenTheCurrentOnesAreAbsent(): void
    {
        $options = GatewayOptions::fromConfig(['apikey' => 'A', 'privatekey' => 'P', 'agreement' => '266017']);

        self::assertSame('A', $options->apiKey);
        self::assertSame('P', $options->privateKey);
        self::assertSame(266017, $options->agreementId);
    }

    /**
     * Null means unset everywhere in the rules — the defaults have always treated it that way, so the
     * aliasing does too.
     */
    #[Test]
    public function shouldReadTheDeprecatedNamesWhenTheCurrentOnesAreNull(): void
    {
        $options = GatewayOptions::fromConfig([
            'api_key' => null,
            'apikey' => 'A',
            'private_key' => null,
            'privatekey' => 'P',
            'agreement_id' => null,
            'agreement' => 7,
        ]);

        self::assertSame('A', $options->apiKey);
        self::assertSame('P', $options->privateKey);
        self::assertSame(7, $options->agreementId);
    }

    #[Test]
    public function shouldPreferANonNullCurrentNameOverTheDeprecatedOne(): void
    {
        $options = GatewayOptions::fromConfig([
            'api_key' => 'current-api-key',
            'apikey' => 'old-api-key',
            'private_key' => 'current-private-key',
            'privatekey' => 'old-private-key',
            'agreement_id' => '',
            'agreement' => 7,
        ]);

        self::assertSame('current-api-key', $options->apiKey);
        self::assertSame('current-private-key', $options->privateKey);
        self::assertNull($options->agreementId, 'An empty current name is still set, and it wins');
    }

    #[Test]
    public function shouldNotFallBackToTheDeprecatedNameForAnEmptyCurrentCredential(): void
    {
        self::assertSame(
            ['api_key'],
            array_keys(self::violationsOf(['api_key' => '', 'apikey' => 'old-api-key'])),
        );
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $expected
     */
    #[Test]
    #[DataProvider('withCurrentNamesProvider')]
    public function shouldRenameTheDeprecatedNamesAndDropThem(array $config, array $expected): void
    {
        self::assertSame($expected, GatewayOptions::withCurrentNames($config));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function withCurrentNamesProvider(): iterable
    {
        yield 'only 1.x names' => [
            ['apikey' => 'A', 'privatekey' => 'P', 'agreement' => 7],
            ['api_key' => 'A', 'private_key' => 'P', 'agreement_id' => 7],
        ];

        yield 'null current names' => [
            ['api_key' => null, 'apikey' => 'A', 'agreement_id' => null, 'agreement' => 7],
            ['api_key' => 'A', 'agreement_id' => 7],
        ];

        yield 'non-null current names win, even empty ones' => [
            ['api_key' => 'current', 'apikey' => 'old', 'agreement_id' => '', 'agreement' => 7],
            ['api_key' => 'current', 'agreement_id' => ''],
        ];

        yield 'a null 1.x name is dropped without a trace' => [
            ['apikey' => null, 'private_key' => 'P'],
            ['private_key' => 'P'],
        ];

        yield 'everything else is left as stored, invalid values and deprecated auto_capture included' => [
            ['api_key' => 'A', 'auto_capture' => 'yes', 'branding_id' => 'abc', 'payum.factory_name' => 'quickpay'],
            ['api_key' => 'A', 'auto_capture' => 'yes', 'branding_id' => 'abc', 'payum.factory_name' => 'quickpay'],
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[Test]
    #[DataProvider('deprecatedOptionsProvider')]
    public function shouldTellWhetherAConfigUsesADeprecatedOption(array $config, bool $expected): void
    {
        self::assertSame($expected, GatewayOptions::usesDeprecatedOptions($config));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function deprecatedOptionsProvider(): iterable
    {
        yield 'an empty config' => [[], false];
        yield 'only current names' => [['api_key' => 'A', 'private_key' => 'P', 'agreement_id' => 7, 'synchronized' => true], false];
        yield 'apikey' => [['apikey' => 'A'], true];
        yield 'privatekey' => [['privatekey' => 'P'], true];
        yield 'agreement' => [['agreement' => 7], true];
        yield 'a 1.x name next to the current one it loses to' => [['api_key' => 'A', 'apikey' => 'old'], true];
        yield 'auto_capture, the only deprecated option' => [['api_key' => 'A', 'private_key' => 'P', 'auto_capture' => 1], true];
        yield 'auto_capture stored as its default' => [['auto_capture' => 0], true];
        yield 'null deprecated options are unset' => [['apikey' => null, 'agreement' => null, 'auto_capture' => null], false];
    }

    /**
     * A full gateway config — Payum's own keys, the client seam, Sylius' `use_authorize`, the long-gone
     * `merchant` — is read for the options it knows and nothing else.
     */
    #[Test]
    public function shouldIgnoreKeysItDoesNotKnow(): void
    {
        $options = GatewayOptions::fromConfig((new QuickpayGatewayFactory())->createConfig([
            'api_key' => 'the-api-key',
            'private_key' => 'the-private-key',
            'quickpay.client' => new stdClass(),
            'use_authorize' => true,
            'merchant' => ['not', 'a', 'string'],
        ]));

        self::assertSame('the-api-key', $options->apiKey);
    }

    /**
     * The SDK client discovers its PSR-18 client in its constructor, so with discovery emptied out any
     * client construction — and with it any request — would throw.
     */
    #[Test]
    public function shouldBuildNoClient(): void
    {
        $strategies = Psr18ClientDiscovery::getStrategies();
        Psr18ClientDiscovery::setStrategies([]);

        try {
            $options = GatewayOptions::fromConfig(['api_key' => 'the-api-key', 'private_key' => 'the-private-key']);
        } finally {
            Psr18ClientDiscovery::setStrategies(is_array($strategies) ? $strategies : iterator_to_array($strategies));
        }

        self::assertSame('the-api-key', $options->apiKey);
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function fromValidConfig(array $options): GatewayOptions
    {
        return GatewayOptions::fromConfig($options + ['api_key' => 'the-api-key', 'private_key' => 'the-private-key']);
    }

    /**
     * The violations of a config that must be rejected, on top of valid credentials unless it sets its own.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, string>
     */
    private static function violationsOf(array $options): array
    {
        try {
            GatewayOptions::fromConfig($options + ['api_key' => 'the-api-key', 'private_key' => 'the-private-key']);
        } catch (InvalidGatewayOptions $e) {
            return $e->getViolations();
        }

        self::fail('The config was accepted');
    }
}
