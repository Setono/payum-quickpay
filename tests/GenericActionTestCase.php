<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Tests;

use ArrayObject;
use Iterator;
use Payum\Core\Action\ActionInterface;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayInterface;
use Payum\Core\Request\Generic;
use Payum\Core\Security\TokenInterface;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * The Payum request class the action under test handles. Static — and redeclared per subclass —
     * because the data providers below read it, and PHPUnit calls data providers statically, before
     * the test case is instantiated (a non-static provider is deprecated in PHPUnit 10, an error in
     * 11). Late static binding resolves it to the subclass's value.
     *
     * @var class-string<Generic>
     */
    protected static string $requestClass;

    /** @var class-string<ActionInterface> */
    protected static string $actionClass;

    protected ActionInterface $action;

    protected function setUp(): void
    {
        $this->action = new static::$actionClass();
    }

    public static function provideSupportedRequests(): Iterator
    {
        yield [new static::$requestClass([])];
        yield [new static::$requestClass(new ArrayObject())];
    }

    public static function provideNotSupportedRequests(): Iterator
    {
        yield ['foo'];
        yield [['foo']];
        yield [new stdClass()];
        yield [new static::$requestClass('foo')];
        yield [new static::$requestClass(new stdClass())];

        // A bare Generic request that is not the action's specific request type. Generic is
        // abstract but declares no abstract methods, so an anonymous subclass stands in for it
        // (Prophecy cannot be used inside a data provider, which runs outside the test lifecycle).
        yield [new class([]) extends Generic {
        }];
    }

    public function testShouldImplementActionInterface(): void
    {
        $rc = new ReflectionClass(static::$actionClass);

        self::assertTrue($rc->implementsInterface(ActionInterface::class));
    }

    /**
     * @param mixed $request
     */
    #[DataProvider('provideSupportedRequests')]
    public function testShouldSupportRequest($request): void
    {
        self::assertTrue($this->action->supports($request));
    }

    /**
     * @param mixed $request
     */
    #[DataProvider('provideNotSupportedRequests')]
    public function testShouldNotSupportRequest($request): void
    {
        self::assertFalse($this->action->supports($request));
    }

    /**
     * @param mixed $request
     */
    #[DataProvider('provideNotSupportedRequests')]
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
