<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Tests;

use ArrayObject;
use Iterator;
use Payum\Core\Action\ActionInterface;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayInterface;
use Payum\Core\Request\Generic;
use Payum\Core\Security\TokenInterface;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use ReflectionClass;
use stdClass;

/**
 * Local copy of {@see \Payum\Core\Tests\GenericActionTest}.
 *
 * Payum exports that class with `export-ignore`, so it is absent from the payum/core dist archive
 * and unavailable on a `--prefer-dist` install (i.e. CI). We keep only what the action tests rely
 * on and create test doubles with Prophecy rather than PHPUnit's mock builder.
 */
abstract class GenericActionTestCase extends TestCase
{
    use ProphecyTrait;

    /** @var Generic */
    protected $requestClass;

    /** @var string */
    protected $actionClass;

    /** @var ActionInterface */
    protected $action;

    protected function setUp(): void
    {
        $this->action = new $this->actionClass();
    }

    public function provideSupportedRequests(): Iterator
    {
        yield [new $this->requestClass([])];
        yield [new $this->requestClass(new ArrayObject())];
    }

    public function provideNotSupportedRequests(): Iterator
    {
        yield ['foo'];
        yield [['foo']];
        yield [new stdClass()];
        yield [new $this->requestClass('foo')];
        yield [new $this->requestClass(new stdClass())];

        // A bare Generic request that is not the action's specific request type. Generic is
        // abstract but declares no abstract methods, so an anonymous subclass stands in for it
        // (Prophecy cannot be used inside a data provider, which runs outside the test lifecycle).
        yield [new class([]) extends Generic {
        }];
    }

    public function testShouldImplementActionInterface(): void
    {
        $rc = new ReflectionClass($this->actionClass);

        self::assertTrue($rc->implementsInterface(ActionInterface::class));
    }

    /**
     * @dataProvider provideSupportedRequests
     *
     * @param mixed $request
     */
    public function testShouldSupportRequest($request): void
    {
        self::assertTrue($this->action->supports($request));
    }

    /**
     * @dataProvider provideNotSupportedRequests
     *
     * @param mixed $request
     */
    public function testShouldNotSupportRequest($request): void
    {
        self::assertFalse($this->action->supports($request));
    }

    /**
     * @dataProvider provideNotSupportedRequests
     *
     * @param mixed $request
     */
    public function testThrowIfNotSupportedRequestGivenAsArgumentForExecute($request): void
    {
        $this->expectException(RequestNotSupportedException::class);
        $this->action->execute($request);
    }

    protected function createGatewayMock(): GatewayInterface
    {
        return $this->prophesize(GatewayInterface::class)->reveal();
    }

    protected function createTokenMock(): TokenInterface
    {
        return $this->prophesize(TokenInterface::class)->reveal();
    }
}
