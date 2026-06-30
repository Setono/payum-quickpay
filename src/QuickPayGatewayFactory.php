<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\LogicException;
use Payum\Core\GatewayFactory;
use Setono\Payum\QuickPay\Action\Api\ConfirmPaymentAction;
use Setono\Payum\QuickPay\Action\AuthorizeAction;
use Setono\Payum\QuickPay\Action\CancelAction;
use Setono\Payum\QuickPay\Action\CaptureAction;
use Setono\Payum\QuickPay\Action\ConvertPaymentAction;
use Setono\Payum\QuickPay\Action\NotifyAction;
use Setono\Payum\QuickPay\Action\RefundAction;
use Setono\Payum\QuickPay\Action\StatusAction;
use Setono\Quickpay\Client\Client;
use Setono\Quickpay\Client\ClientInterface;

class QuickPayGatewayFactory extends GatewayFactory
{
    protected function populateConfig(ArrayObject $config): void
    {
        $config->defaults([
            'payum.factory_name' => 'quickpay',
            'payum.factory_title' => 'QuickPay',
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
                // optional: maps to the QuickPay branding id on the payment link
                'branding_id' => '',
            ];
            $config->defaults($config['payum.default_options']);
            $config['payum.required_options'] = [
                'apikey',
                'privatekey',
            ];

            $config['payum.api'] = static function (ArrayObject $config): Api {
                $config->validateNotEmpty($config['payum.required_options']);

                // Consumers (and the test suite) may inject a preconfigured SDK client — e.g. one
                // built around a specific PSR-18 client — via the "quickpay.client" option.
                // Otherwise we build one from the api key and let php-http/discovery find a client.
                $client = $config['quickpay.client'] ?? new Client((string) $config['apikey']);
                if (!$client instanceof ClientInterface) {
                    throw new LogicException(sprintf(
                        'The "quickpay.client" option must be an instance of %s',
                        ClientInterface::class,
                    ));
                }

                return new Api(
                    client: $client,
                    privateKey: (string) $config['privatekey'],
                    orderPrefix: (string) $config['order_prefix'],
                    paymentMethods: (string) $config['payment_methods'],
                    language: (string) $config['language'],
                    autoCapture: (bool) (int) $config['auto_capture'],
                    synchronized: (bool) $config['synchronized'],
                    agreementId: '' !== (string) $config['agreement'] ? (int) $config['agreement'] : null,
                    brandingId: '' !== (string) $config['branding_id'] ? (int) $config['branding_id'] : null,
                );
            };
        }
    }
}
