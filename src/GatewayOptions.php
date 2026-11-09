<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay;

use Setono\Payum\Quickpay\Exception\InvalidGatewayOptions;

/**
 * The Quickpay gateway options, read from a gateway configuration by exactly the rules the gateway is
 * built from — {@see QuickpayGatewayFactory} builds its {@see Api} from this, so there is one place that
 * decides what an option means.
 *
 * It is public so that consumers that store gateway configurations (the Sylius plugin, first of all) can
 * validate and read one — for a form, a health check, a callback endpoint resolving the order prefix —
 * without building a gateway, which would create an SDK client, stop at the first invalid option and
 * never expose the api key. {@see self::fromConfig()} creates no client and makes no network call.
 *
 * Throughout, **null means unset**: a stored configuration may carry null where a form field was left
 * empty, and it reads as an absent key — the default applies, and so does a deprecated 1.x name.
 */
final class GatewayOptions
{
    /**
     * The defaults applied to an option that is absent or null — and Payum's `payum.default_options`,
     * which the factory publishes unchanged. The empty strings are Payum config artifacts: each
     * normalizes to the option's "not configured" value (`null`, or `false`).
     *
     * @var array<string, mixed>
     */
    public const DEFAULTS = [
        'api_key' => '',
        'private_key' => '',
        'payment_methods' => '',
        'auto_capture' => 0,
        'order_prefix' => '',
        'language' => 'en',
        'synchronized' => false,
        'agreement_id' => '',
        'branding_id' => '',
    ];

    /**
     * The 1.x option names, deprecated aliases of the current ones until 3.0. The current names say what
     * they are — `api_key`/`private_key` are snake_case like every other multi-word option, and
     * `agreement_id` matches its sibling `branding_id`.
     *
     * They are aliased rather than dropped because Sylius stores gateway configurations keyed by exactly
     * these names, so a hard rename would break every existing shop. `agreement` needs the most care: it is
     * optional, so a name that silently stopped being read would not fail — the payment link would be
     * created without an agreement id and Quickpay would fall back to the account default.
     */
    private const DEPRECATED_NAMES = [
        'apikey' => 'api_key',
        'privatekey' => 'private_key',
        'agreement' => 'agreement_id',
    ];

    /**
     * @param string|null $paymentMethods Quickpay's comma-separated form, or null for no restriction
     * @param int|null $agreementId null for the account's default agreement
     * @param int|null $brandingId null for the account's default branding
     */
    private function __construct(
        public readonly string $apiKey,
        public readonly string $privateKey,
        public readonly string $orderPrefix,
        public readonly ?string $paymentMethods,
        public readonly string $language,
        public readonly bool $autoCapture,
        public readonly bool $synchronized,
        public readonly ?int $agreementId,
        public readonly ?int $brandingId,
    ) {
    }

    /**
     * Reads the options from a gateway configuration: the deprecated names are applied
     * ({@see self::withCurrentNames()}), then the defaults, then every option is normalized. Keys it does
     * not know — Payum's `payum.*`, `quickpay.client`, Sylius' `use_authorize` — are ignored.
     *
     * @param array<array-key, mixed> $config
     *
     * @throws InvalidGatewayOptions listing every invalid option, not just the first
     */
    public static function fromConfig(array $config): self
    {
        $config = self::withCurrentNames($config);

        foreach (self::DEFAULTS as $option => $default) {
            $config[$option] ??= $default;
        }

        $violations = [];

        $options = new self(
            apiKey: self::requireString('api_key', $config['api_key'], $violations),
            privateKey: self::requireString('private_key', $config['private_key'], $violations),
            orderPrefix: self::normalizeString('order_prefix', $config['order_prefix'], $violations),
            paymentMethods: self::normalizePaymentMethods($config['payment_methods'], $violations),
            language: self::normalizeString('language', $config['language'], $violations),
            autoCapture: self::normalizeBool('auto_capture', $config['auto_capture'], $violations),
            synchronized: self::normalizeBool('synchronized', $config['synchronized'], $violations),
            agreementId: self::normalizeId('agreement_id', $config['agreement_id'], $violations),
            brandingId: self::normalizeId('branding_id', $config['branding_id'], $violations),
        );

        if ([] !== $violations) {
            throw new InvalidGatewayOptions($violations);
        }

        return $options;
    }

    /**
     * Renames the 1.x option names (`apikey`, `privatekey`, `agreement`) to the current ones and drops
     * the old keys — nothing else is touched or validated, so a configuration the gateway would reject
     * can still be shown under the current names. A 1.x name applies when the current name is absent or
     * null; a current name that is set, even to `''`, wins.
     *
     * @param array<array-key, mixed> $config
     *
     * @return array<array-key, mixed>
     */
    public static function withCurrentNames(array $config): array
    {
        foreach (self::DEPRECATED_NAMES as $deprecated => $current) {
            if (null === ($config[$current] ?? null) && null !== ($config[$deprecated] ?? null)) {
                $config[$current] = $config[$deprecated];
            }

            unset($config[$deprecated]);
        }

        return $config;
    }

    /**
     * Whether the configuration still sets a deprecated gateway option: one of the 1.x names, or
     * `auto_capture`, which is deprecated as a whole (execute `Capture` instead of `Authorize`). A null
     * value is unset, so it does not count.
     *
     * @param array<array-key, mixed> $config
     */
    public static function usesDeprecatedOptions(array $config): bool
    {
        foreach ([...array_keys(self::DEPRECATED_NAMES), 'auto_capture'] as $option) {
            if (null !== ($config[$option] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A credential is required. "Empty" is what Payum's own required-options check has always meant by it:
     * `''` and `'0'` (and `false`, which reads as `''`) are missing.
     *
     * @param array<string, string> $violations
     */
    private static function requireString(string $option, mixed $value, array &$violations): string
    {
        $value = self::normalizeString($option, $value, $violations);

        if (('' === $value || '0' === $value) && !isset($violations[$option])) {
            $violations[$option] = sprintf('The "%s" option is required', $option);
        }

        return $value;
    }

    /**
     * The string options have always been read with a `(string)` cast, so a scalar keeps working as it
     * does at the gateway. A non-scalar is a violation — the cast would have sent `Array`, or thrown.
     *
     * @param array<string, string> $violations
     */
    private static function normalizeString(string $option, mixed $value, array &$violations): string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }

        $violations[$option] = sprintf('The "%s" option must be a string, got %s', $option, self::describe($value));

        return '';
    }

    /**
     * The id options (`agreement_id`, `branding_id`) are optional positive integers. A blank string is
     * `null`, which keeps the parameter off the payment link entirely. Anything that is not a positive
     * integer or a digit string is a violation: an `(int)` cast turned a typo like `"abc"` into id 0 and
     * sent that, and `'1e3'` or `12.0` are numbers PHP would coerce but not ids anyone meant.
     *
     * @param array<string, string> $violations
     */
    private static function normalizeId(string $option, mixed $value, array &$violations): ?int
    {
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
            $violations[$option] = sprintf(
                'The "%s" option must be a positive integer (or an integer string), got %s',
                $option,
                self::describe($value),
            );

            return null;
        }

        return $value;
    }

    /**
     * The boolean options (`auto_capture`, `synchronized`) accept booleans plus the unambiguous scalar
     * spellings a stored or YAML-sourced configuration produces — PHP's bool filter: `1`/`0`,
     * `"true"`/`"false"`, `"yes"`/`"no"`, `"on"`/`"off"` and `''` (false). Anything else is a violation
     * rather than being cast: `(bool) "false"` is true, and `(int) "true"` is 0 — an inverted setting with
     * nothing in the configuration that looks wrong.
     *
     * @param array<string, string> $violations
     */
    private static function normalizeBool(string $option, mixed $value, array &$violations): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            $normalized = filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE);

            if (null !== $normalized) {
                return $normalized;
            }
        }

        $violations[$option] = sprintf(
            'The "%s" option must be a boolean (or an unambiguous scalar such as 1/0/"true"/"false"), got %s',
            $option,
            self::describe($value),
        );

        return false;
    }

    /**
     * Quickpay wants `payment_methods` as one comma-separated string (see {@see Api::getPaymentMethods()}),
     * but a list is the natural way to write it in YAML or a stored configuration, so both shapes are
     * accepted: a list is trimmed, its blanks dropped and the rest joined. Anything else is a violation —
     * a `(string)` cast of a list would send the literal `Array`, which Quickpay reads as an allowlist of
     * one unknown method, rejecting every payment.
     *
     * Blank configuration — `''`, an empty list, a list of blanks — is `null`, which leaves the
     * restriction off the request so the account's own configuration applies.
     *
     * @param array<string, string> $violations
     */
    private static function normalizePaymentMethods(mixed $value, array &$violations): ?string
    {
        if (is_string($value)) {
            $value = trim($value);

            return '' !== $value ? $value : null;
        }

        if (!is_array($value)) {
            $violations['payment_methods'] = sprintf(
                'The "payment_methods" option must be a string or a list of strings, got %s',
                self::describe($value),
            );

            return null;
        }

        $methods = [];

        foreach ($value as $method) {
            if (!is_string($method)) {
                $violations['payment_methods'] = sprintf(
                    'The "payment_methods" option must be a string or a list of strings, but the list contains %s',
                    self::describe($method),
                );

                return null;
            }

            $method = trim($method);

            if ('' !== $method) {
                $methods[] = $method;
            }
        }

        return [] !== $methods ? implode(',', $methods) : null;
    }

    private static function describe(mixed $value): string
    {
        return is_scalar($value) ? var_export($value, true) : get_debug_type($value);
    }
}
