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
            // The current names are filled in from the 1.x ones before the defaults are applied: once
            // `api_key` exists as `''` there is no telling that the consumer only supplied `apikey`.
            // replace() only adds, so the 1.x keys stay in the config as they always have.
            $config->replace(GatewayOptions::withCurrentNames($config->getArrayCopy()));

            $config['payum.default_options'] = GatewayOptions::DEFAULTS;
            $config->defaults($config['payum.default_options']);
            $config['payum.required_options'] = [
                'api_key',
                'private_key',
            ];

            $config['payum.api'] = static function (ArrayObject $config): Api {
                $options = GatewayOptions::fromConfig($config->getArrayCopy());

                // GatewayOptions has enforced the credentials; this is for any option a subclass added.
                $config->validateNotEmpty($config['payum.required_options']);

                // Consumers (and the test suite) may inject a preconfigured SDK client — e.g. one
                // built around a specific PSR-18 client — via the "quickpay.client" option.
                // Otherwise we build one from the api key and let php-http/discovery find a client.
                $client = $config['quickpay.client'] ?? new Client($options->apiKey, synchronized: $options->synchronized);
                if (!$client instanceof ClientInterface) {
                    throw new LogicException(sprintf(
                        'The "quickpay.client" option must be an instance of %s',
                        ClientInterface::class,
                    ));
                }

                // The SDK client carries the synchronized flag as a client-wide default, and it can
                // only be set on its constructor. An injected client that disagrees with the gateway
                // option would silently win, so reject the mismatch instead.
                if ($client->isSynchronized() !== $options->synchronized) {
                    throw new LogicException(sprintf(
                        'The injected "quickpay.client" is %ssynchronized, but the gateway is configured with "synchronized" = %s. Construct the client with the same value.',
                        $client->isSynchronized() ? '' : 'not ',
                        $options->synchronized ? 'true' : 'false',
                    ));
                }

                return new Api(
                    client: $client,
                    privateKey: $options->privateKey,
                    orderPrefix: $options->orderPrefix,
                    paymentMethods: $options->paymentMethods,
                    language: $options->language,
                    autoCapture: $options->autoCapture,
                    agreementId: $options->agreementId,
                    brandingId: $options->brandingId,
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
}
