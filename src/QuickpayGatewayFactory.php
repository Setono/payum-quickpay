<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\LogicException;
use Payum\Core\GatewayFactory;
use Setono\Payum\Quickpay\Action\Api\ConfirmPaymentAction;
use Setono\Payum\Quickpay\Action\AuthorizeAction;
use Setono\Payum\Quickpay\Action\CancelAction;
use Setono\Payum\Quickpay\Action\CaptureAction;
use Setono\Payum\Quickpay\Action\ConvertPaymentAction;
use Setono\Payum\Quickpay\Action\NotifyAction;
use Setono\Payum\Quickpay\Action\RefundAction;
use Setono\Payum\Quickpay\Action\StatusAction;
use Setono\Quickpay\Client\Client;
use Setono\Quickpay\Client\ClientInterface;

class QuickpayGatewayFactory extends GatewayFactory
{
    protected function populateConfig(ArrayObject $config): void
    {
        $config->defaults([
            'payum.factory_name' => 'quickpay',
            'payum.factory_title' => 'Quickpay',
            'payum.action.capture' => new CaptureAction(),
            'payum.action.authorize' => new AuthorizeAction(),
            'payum.action.refund' => new RefundAction(),
            'payum.action.cancel' => new CancelAction(),
            'payum.action.notify' => new NotifyAction(),
            'payum.action.status' => new StatusAction(),
            'payum.action.convert_payment' => new ConvertPaymentAction(),
            'payum.action.api.confirm_payment' => new ConfirmPaymentAction(),
        ]);

        if (!$config->offsetExists('payum.api')) {
            $config['payum.default_options'] = [
                'apikey' => '',
                'privatekey' => '',
                'payment_methods' => '',
                'auto_capture' => 0,
                'order_prefix' => '',
                'language' => 'en',
                'synchronized' => false,
                // optional: maps to CreateLinkRequest::agreementId
                'agreement' => '',
                // optional: maps to the Quickpay branding id on the payment link
                'branding_id' => '',
            ];
            $config->defaults($config['payum.default_options']);
            $config['payum.required_options'] = [
                'apikey',
                'privatekey',
            ];

            $config['payum.api'] = static function (ArrayObject $config): Api {
                $config->validateNotEmpty($config['payum.required_options']);

                $synchronized = (bool) $config['synchronized'];

                // Consumers (and the test suite) may inject a preconfigured SDK client — e.g. one
                // built around a specific PSR-18 client — via the "quickpay.client" option.
                // Otherwise we build one from the api key and let php-http/discovery find a client.
                $client = $config['quickpay.client'] ?? new Client((string) $config['apikey'], synchronized: $synchronized);
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
                    privateKey: (string) $config['privatekey'],
                    orderPrefix: (string) $config['order_prefix'],
                    paymentMethods: self::normalizePaymentMethods($config['payment_methods']),
                    language: (string) $config['language'],
                    autoCapture: (bool) (int) $config['auto_capture'],
                    agreementId: '' !== (string) $config['agreement'] ? (int) $config['agreement'] : null,
                    brandingId: '' !== (string) $config['branding_id'] ? (int) $config['branding_id'] : null,
                );
            };
        }
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
     * artifact and stops here, exactly as `agreement` and `branding_id` do.
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
