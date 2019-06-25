<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Tests\Action;

use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\Tests\GenericActionTest;
use ReflectionClass;
use ReflectionException;
use Setono\Payum\QuickPay\Tests\ApiTestTrait;

abstract class ActionTestAbstract extends GenericActionTest
{
    use ApiTestTrait;

    /**
     * @test
     *
     * @throws ReflectionException
     */
    public function shouldImplementActionInterface(): void
    {
        $rc = new ReflectionClass($this->actionClass);

        $this->assertTrue($rc->implementsInterface(ActionInterface::class));
    }

    /**
     * @test
     *
     * @throws ReflectionException
     */
    public function shouldImplementApiAwareInterface(): void
    {
        $rc = new ReflectionClass($this->actionClass);

        $this->assertTrue($rc->implementsInterface(ApiAwareInterface::class));
    }

    /**
     * @test
     *
     * @throws ReflectionException
     */
    public function shouldImplementGatewayAwareInterface(): void
    {
        $rc = new ReflectionClass($this->actionClass);

        $this->assertTrue($rc->implementsInterface(GatewayAwareInterface::class));
    }
}
