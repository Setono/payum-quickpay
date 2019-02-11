<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Tests\Action;

use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayInterface;
use Payum\Core\Model\Payment;
use Payum\Core\Tests\GenericActionTest;
use Setono\Payum\QuickPay\Api;
use Setono\Payum\QuickPay\QuickPayGatewayFactory;

abstract class ActionTestAbstract extends GenericActionTest
{
    /** @var GatewayInterface */
    protected $gateway;

    /** @var Api */
    protected $api;

    /**
     * {@inheritdoc}
     *
     * @throws \ReflectionException
     */
    public function setUp(): void
    {
        parent::setUp();

        $this->gateway = $this->createGatewayMock();
        $this->api = $this->getApi();
    }

    /**
     * @test
     *
     * @throws \ReflectionException
     */
    public function shouldImplementActionInterface(): void
    {
        $rc = new \ReflectionClass($this->actionClass);

        $this->assertTrue($rc->implementsInterface(ActionInterface::class));
    }

    /**
     * @test
     *
     * @throws \ReflectionException
     */
    public function shouldImplementApiAwareInterface(): void
    {
        $rc = new \ReflectionClass($this->actionClass);

        $this->assertTrue($rc->implementsInterface(ApiAwareInterface::class));
    }

    /**
     * @test
     *
     * @throws \ReflectionException
     */
    public function shouldImplementGatewayAwareInterface(): void
    {
        $rc = new \ReflectionClass($this->actionClass);

        $this->assertTrue($rc->implementsInterface(GatewayAwareInterface::class));
    }

    /**
     * @return Payment
     *
     * @throws \Exception
     */
    protected function createPayment(): Payment
    {
        $payment = new Payment();
        $payment->setNumber(time());
        $payment->setTotalAmount(random_int(1, 100) * 100);
        $payment->setCurrencyCode('DKK');

        return $payment;
    }

    /**
     * @return GatewayInterface
     */
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
        ]);

        return $gateway;
    }

    /**
     * @return mixed
     *
     * @throws \ReflectionException
     * @throws \Exception
     */
    private function getApi(): Api
    {
        $attribute = new \ReflectionProperty($this->gateway, 'apis');

        $attribute->setAccessible(true);
        $value = $attribute->getValue($this->gateway);
        $attribute->setAccessible(false);

        foreach ($value as $api) {
            if ($api instanceof Api) {
                return $api;
            }
        }

        throw new \RuntimeException('No api found in gateway');
    }
}
