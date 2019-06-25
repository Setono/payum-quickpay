<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\GatewayFactory;
use Setono\Payum\QuickPay\Action\Api\ConfirmPaymentAction;
use Setono\Payum\QuickPay\Action\AuthorizeAction;
use Setono\Payum\QuickPay\Action\CancelAction;
use Setono\Payum\QuickPay\Action\CaptureAction;
use Setono\Payum\QuickPay\Action\ConvertPaymentAction;
use Setono\Payum\QuickPay\Action\NotifyAction;
use Setono\Payum\QuickPay\Action\RefundAction;
use Setono\Payum\QuickPay\Action\StatusAction;

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
            $config['payum.default_options'] = array(
                'apikey' => '',
                'merchant' => '',
                'agreement' => '',
                'privatekey' => '',
                'payment_methods' => '',
                'auto_capture' => 0,
                'order_prefix' => '',
                'syncronized' => false,
            );
            $config->defaults($config['payum.default_options']);

            $config['payum.api'] = static function (ArrayObject $config) {
                $config->validateNotEmpty($config['payum.required_options']);

                return new Api((array) $config, $config['payum.http_client'], $config['httplug.message_factory']);
            };
        }
    }
}
