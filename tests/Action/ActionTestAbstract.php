<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Tests\Action;

use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\GatewayAwareInterface;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionException;
use Setono\Payum\Quickpay\Tests\ApiTestTrait;
use Setono\Payum\Quickpay\Tests\GenericActionTestCase;

abstract class ActionTestAbstract extends GenericActionTestCase
{
    use ApiTestTrait;

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function shouldImplementActionInterface(): void
    {
        $rc = new ReflectionClass(static::$actionClass);

        self::assertTrue($rc->implementsInterface(ActionInterface::class));
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function shouldImplementApiAwareInterface(): void
    {
        $rc = new ReflectionClass(static::$actionClass);

        self::assertTrue($rc->implementsInterface(ApiAwareInterface::class));
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function shouldImplementGatewayAwareInterface(): void
    {
        $rc = new ReflectionClass(static::$actionClass);

        self::assertTrue($rc->implementsInterface(GatewayAwareInterface::class));
    }
}
