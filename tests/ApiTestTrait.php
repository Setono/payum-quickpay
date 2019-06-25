<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Tests;

use DateTime;
use Exception;
use Payum\Core\GatewayInterface;
use Payum\Core\Model\Payment;
use ReflectionException;
use ReflectionProperty;
use RuntimeException;
use Setono\Payum\QuickPay\Api;
use Setono\Payum\QuickPay\Model\QuickpayCard;
use Setono\Payum\QuickPay\QuickPayGatewayFactory;

trait ApiTestTrait
{
    /** @var GatewayInterface */
    protected $gateway;

    /** @var Api */
    protected $api;

    /**
     * {@inheritdoc}
     *
     * @throws ReflectionException
     */
    public function setUp(): void
    {
        parent::setUp();

        $this->gateway = $this->createGatewayMock();
        $this->api = $this->getApi();
    }

    protected function createGatewayMock(): GatewayInterface
    {
        $factory = new QuickPayGatewayFactory();
        $gateway = $factory->create([
            'apikey' => '3fc7aa8a994d871d920c1c4b4de20129274ecdc7f023257e0b2b1f773771fda8',
            'privatekey' => '31f1ec081caad82046e3bd763d81df4629e498cbc286fe66f0286bb74d7e9d0e',
            'merchant' => '75015',
            'agreement' => '266017',
            'order_prefix' => 'ut',
            'payment_methods' => 'visa',
            'auto_capture' => '1',
        ]);

        return $gateway;
    }

    /**
     * @return mixed
     *
     * @throws ReflectionException
     * @throws Exception
     */
    private function getApi(): Api
    {
        $attribute = new ReflectionProperty($this->gateway, 'apis');

        $attribute->setAccessible(true);
        $value = $attribute->getValue($this->gateway);
        $attribute->setAccessible(false);

        foreach ($value as $api) {
            if ($api instanceof Api) {
                return $api;
            }
        }

        throw new RuntimeException('No api found in gateway');
    }

    /**
     * @throws Exception
     */
    protected function createPayment(): Payment
    {
        $payment = new Payment();
        $payment->setNumber(substr(uniqid('', true), 0, 18));
        $payment->setTotalAmount(random_int(1, 100) * 100);
        $payment->setCurrencyCode('DKK');

        return $payment;
    }

    /**
     * @throws Exception
     */
    protected function getTestCard(): QuickpayCard
    {
        return QuickpayCard::createFromArray([
            'number' => 1000000000000008,
            'expiration' => (new DateTime())->format('ym'),
            'cvd' => 123,
        ]);
    }

    /**
     * @throws Exception
     */
    protected function getAuthorizeRejectedTestCard(): QuickpayCard
    {
        $card = $this->getTestCard();
        $card->setNumber($card->getNumber() + 8);

        return $card;
    }

    /**
     * @throws Exception
     */
    protected function getCaptureRejectedTestCard(): QuickpayCard
    {
        $card = $this->getTestCard();
        $card->setNumber($card->getNumber() + 24);

        return $card;
    }
}
