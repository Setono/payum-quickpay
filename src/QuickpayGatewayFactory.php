<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay;

use Payum\Core\Bridge\PlainPhp\Action\GetHttpRequestAction;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\LogicException;
use Payum\Core\GatewayFactory;
use Setono\Payum\Quickpay\Action\Api\ConfirmPaymentAction;
use Setono\Payum\Quickpay\Action\Api\CreatePaymentLinkAction;
use Setono\Payum\Quickpay\Action\AuthorizeAction;
use Setono\Payum\Quickpay\Action\CancelAction;
use Setono\Payum\Quickpay\Action\CaptureAction;
use Setono\Payum\Quickpay\Action\ConvertPaymentAction;
use Setono\Payum\Quickpay\Action\NotifyAction;
use Setono\Payum\Quickpay\Action\RefundAction;
use Setono\Payum\Quickpay\Action\StatusAction;
use Setono\Payum\Quickpay\Action\SyncAction;
use Setono\Payum\Quickpay\Bridge\PlainPhp\Action\HeaderAwareGetHttpRequestAction;
use Setono\Quickpay\Client\Client;
use Setono\Quickpay\Client\ClientInterface;

class QuickpayGatewayFactory extends GatewayFactory
{
    /**
     * The Payum factory name this gateway registers under.
     *
     * This package sets `payum.factory_name`, so it is the authority on the value — consumers that need
     * it (to look gateways up by `factoryName`, to tag services, or to guard "is this a Quickpay
     * payment?") should reference this constant instead of repeating the literal.
     *
     * The value is part of the public contract: changing it would orphan every stored gateway
     * configuration that records the factory name.
     */
    public const NAME = 'quickpay';

    protected function populateConfig(ArrayObject $config): void
    {
        self::exposeHeadersToNotify($config);

        $config->defaults([
            'payum.factory_name' => self::NAME,
            'payum.factory_title' => 'Quickpay',
            'payum.action.capture' => new CaptureAction(),
            'payum.action.authorize' => new AuthorizeAction(),
            'payum.action.refund' => new RefundAction(),
            'payum.action.cancel' => new CancelAction(),
            'payum.action.notify' => new NotifyAction(),
            'payum.action.status' => new StatusAction(),
            'payum.action.sync' => new SyncAction(),
            'payum.action.convert_payment' => new ConvertPaymentAction(),
            'payum.action.api.confirm_payment' => new ConfirmPaymentAction(),
            'payum.action.api.create_payment_link' => new CreatePaymentLinkAction(),
        ]);

        if (!$config->offsetExists('payum.api')) {
            self::aliasDeprecatedOptions($config);

            $config['payum.default_options'] = [
                'api_key' => '',
                'private_key' => '',
                'payment_methods' => '',
                'auto_capture' => 0,
                'order_prefix' => '',
                'language' => 'en',
                'synchronized' => false,
                // optional: maps to CreateLinkRequest::agreementId
                'agreement_id' => '',
                // optional: maps to the Quickpay branding id on the payment link
                'branding_id' => '',
            ];
            $config->defaults($config['payum.default_options']);
            $config['payum.required_options'] = [
                'api_key',
                'private_key',
            ];

            $config['payum.api'] = static function (ArrayObject $config): Api {
                $config->validateNotEmpty($config['payum.required_options']);

                $synchronized = self::normalizeBool('synchronized', $config['synchronized']);

                // Consumers (and the test suite) may inject a preconfigured SDK client — e.g. one
                // built around a specific PSR-18 client — via the "quickpay.client" option.
                // Otherwise we build one from the api key and let php-http/discovery find a client.
                $client = $config['quickpay.client'] ?? new Client((string) $config['api_key'], synchronized: $synchronized);
                if (!$client instanceof ClientInterface) {
                    throw new LogicException(sprintf(
                        'The "quickpay.client" option must be an instance of %s',
                        ClientInterface::class,
                    ));
                }

                // The SDK client carries the synchronized flag as a client-wide default, and it can
                // only be set on its constructor. An injected client that disagrees with the gateway
                // option would silently win, so reject the mismatch instead.
                if ($client->isSynchronized() !== $synchronized) {
                    throw new LogicException(sprintf(
                        'The injected "quickpay.client" is %ssynchronized, but the gateway is configured with "synchronized" = %s. Construct the client with the same value.',
                        $client->isSynchronized() ? '' : 'not ',
                        $synchronized ? 'true' : 'false',
                    ));
                }

                return new Api(
                    client: $client,
                    privateKey: (string) $config['private_key'],
                    orderPrefix: (string) $config['order_prefix'],
                    paymentMethods: self::normalizePaymentMethods($config['payment_methods']),
                    language: (string) $config['language'],
                    autoCapture: self::normalizeBool('auto_capture', $config['auto_capture']),
                    agreementId: self::normalizeId('agreement_id', $config['agreement_id']),
                    brandingId: self::normalizeId('branding_id', $config['branding_id']),
                );
            };
        }
    }

    /**
     * The callback verification in {@see NotifyAction} reads the `QuickPay-Checksum-Sha256` header off
     * `GetHttpRequest::$headers`, and among payum/core's own bridges only the Symfony one sets that
     * property. Payum's core config puts its plain-PHP `GetHttpRequestAction` in place before this
     * factory runs, so a plain-PHP Payum that did not know to swap it in rejected EVERY callback as
     * unsigned, with a `400`, silently — for every payment. The core action is replaced with the
     * package's {@see HeaderAwareGetHttpRequestAction}, a subclass that adds the headers, so the
     * default just works.
     *
     * Only payum's exact class is replaced: the Symfony bridge (a different class, wired by
     * PayumBundle — Sylius) is left alone, and so is anything a consumer configured deliberately —
     * a subclass of payum's action, another action, a service id. What is swapped is the one value
     * nobody chose.
     *
     * @param ArrayObject<string, mixed> $config
     */
    private static function exposeHeadersToNotify(ArrayObject $config): void
    {
        if ($config->offsetExists('payum.action.get_http_request') &&
            is_object($config['payum.action.get_http_request']) &&
            GetHttpRequestAction::class === get_class($config['payum.action.get_http_request'])) {
            $config['payum.action.get_http_request'] = new HeaderAwareGetHttpRequestAction();
        }
    }

    /**
     * The option names say what they are: the credentials are `api_key` and `private_key` (snake_case,
     * like `payment_methods`, `auto_capture`, `order_prefix`), and `agreement_id` matches its sibling
     * `branding_id` — both are optional integer payment-link ids. The 1.x spellings `apikey`,
     * `privatekey` and `agreement` still work.
     *
     * They are aliased rather than dropped because Sylius stores the gateway configuration as JSON keyed
     * by exactly these names, so a hard rename would break every existing shop until its stored config
     * was migrated. Unlike the misspelled `syncronized` that 2.0 removed outright — an option nobody had
     * meaningfully set — these are load-bearing, and `agreement` fails *silently* if missed: it is
     * optional, so a stale key resolves to `null` and the payment link is simply created without an
     * agreement id, quietly falling back to the account default. The aliases are deprecated and go in 3.0.
     *
     * Must run before the defaults are applied: once `api_key` exists (as `''`), there is no longer any
     * way to tell that the consumer only supplied the old spelling.
     *
     * @param ArrayObject<string, mixed> $config
     */
    private static function aliasDeprecatedOptions(ArrayObject $config): void
    {
        foreach ([
            'apikey' => 'api_key',
            'privatekey' => 'private_key',
            'agreement' => 'agreement_id',
        ] as $deprecated => $current) {
            if ($config->offsetExists($deprecated) && !$config->offsetExists($current)) {
                $config[$current] = $config[$deprecated];
            }
        }
    }

    /**
     * The boolean options (`auto_capture`, `synchronized`) accept booleans plus the unambiguous
     * scalar spellings a stored or YAML-sourced config produces: `1`/`0` (int or string),
     * `"true"`/`"false"`, and the empty string (a Payum config artifact, read as false). Anything
     * else throws rather than being coerced: the old casts turned the string `"true"` into FALSE
     * (`(int) "true"` is 0) and `"false"` into TRUE — an inverted setting with nothing in the
     * configuration that looks wrong.
     *
     * @throws LogicException if the value cannot be unambiguously read as a boolean
     */
    private static function normalizeBool(string $option, mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        // Absent-but-set: a stored config may carry null where the form field was empty. Like the
        // empty string, it reads as "not configured", i.e. the option's default of false.
        if (null === $value) {
            return false;
        }

        if (is_scalar($value)) {
            $normalized = filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE);

            if (null !== $normalized) {
                return $normalized;
            }
        }

        throw new LogicException(sprintf(
            'The "%s" option must be a boolean (or an unambiguous scalar such as 1/0/"true"/"false"), got %s',
            $option,
            is_scalar($value) ? var_export($value, true) : get_debug_type($value),
        ));
    }

    /**
     * The id options (`agreement_id`, `branding_id`) are optional positive integers. Empty
     * configuration — null, `''`, or a blank string — is `null`, which keeps the parameter off the
     * payment link entirely. Anything that is not a positive integer (or an integer string) throws:
     * the old `(int)` cast turned a typo like `"abc"` into agreement id 0 and sent that.
     *
     * @throws LogicException if the value is neither empty nor a positive integer
     */
    private static function normalizeId(string $option, mixed $value): ?int
    {
        if (null === $value) {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);

            if ('' === $value) {
                return null;
            }

            if (ctype_digit($value)) {
                $value = (int) $value;
            }
        }

        if (!is_int($value) || $value <= 0) {
            throw new LogicException(sprintf(
                'The "%s" option must be a positive integer (or an integer string), got %s',
                $option,
                is_scalar($value) ? var_export($value, true) : get_debug_type($value),
            ));
        }

        return $value;
    }

    /**
     * Quickpay wants `payment_methods` as one comma-separated string (see {@see Api::getPaymentMethods()}),
     * but a list is the natural way to write it in YAML or a stored gateway-config blob. Casting a list
     * with `(string)` would raise an "Array to string conversion" warning and send the literal `Array`,
     * which Quickpay reads as an allowlist naming one unknown method — every payment would then be
     * rejected, with nothing in the configuration that looks wrong. So accept both shapes, and reject
     * anything else outright rather than coercing it into a silently broken restriction.
     *
     * Empty configuration — the default `''`, an empty list, or a list of blanks — becomes `null`, the
     * value that leaves the restriction off the request altogether. The empty string is a Payum config
     * artifact and stops here, exactly as `agreement_id` and `branding_id` do.
     *
     * @throws LogicException if the option is neither a string nor a list of strings
     */
    private static function normalizePaymentMethods(mixed $paymentMethods): ?string
    {
        if (null === $paymentMethods) {
            return null;
        }

        if (is_string($paymentMethods)) {
            $paymentMethods = trim($paymentMethods);

            return '' !== $paymentMethods ? $paymentMethods : null;
        }

        if (!is_array($paymentMethods)) {
            throw new LogicException(sprintf(
                'The "payment_methods" option must be a string or a list of strings, %s given',
                get_debug_type($paymentMethods),
            ));
        }

        $methods = [];

        foreach ($paymentMethods as $paymentMethod) {
            if (!is_string($paymentMethod)) {
                throw new LogicException(sprintf(
                    'The "payment_methods" option must be a string or a list of strings, but the list contains %s',
                    get_debug_type($paymentMethod),
                ));
            }

            $paymentMethod = trim($paymentMethod);

            if ('' !== $paymentMethod) {
                $methods[] = $paymentMethod;
            }
        }

        return [] !== $methods ? implode(',', $methods) : null;
    }
}
