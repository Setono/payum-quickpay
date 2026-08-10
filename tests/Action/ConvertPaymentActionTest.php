<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Tests\Action;

use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Exception\LogicException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\Model\Payment;
use Payum\Core\Model\Token;
use Payum\Core\Request\Convert;
use PHPUnit\Framework\TestCase;
use Setono\Payum\Quickpay\Action\ConvertPaymentAction;
use Setono\Payum\Quickpay\Tests\ApiTestTrait;
use stdClass;

/**
 * {@see Convert} is not a {@see \Payum\Core\Request\Generic} and has a three-argument constructor, so
 * this test cannot use the generic {@see GenericActionTestCase} providers and stands on its own.
 */
class ConvertPaymentActionTest extends TestCase
{
    use ApiTestTrait;

    /**
     * @test
     */
    public function shouldImplementExpectedInterfaces(): void
    {
        $action = new ConvertPaymentAction();

        self::assertInstanceOf(ActionInterface::class, $action);
        self::assertInstanceOf(ApiAwareInterface::class, $action);
        self::assertInstanceOf(GatewayAwareInterface::class, $action);
    }

    /**
     * @test
     */
    public function shouldSupportConvertingAPaymentToArray(): void
    {
        $action = new ConvertPaymentAction();

        self::assertTrue($action->supports(new Convert(new Payment(), 'array')));
        self::assertFalse($action->supports(new Convert(new Payment(), 'json')));
        self::assertFalse($action->supports(new Convert(new stdClass(), 'array')));
        self::assertFalse($action->supports('foo'));
    }

    /**
     * @test
     */
    public function shouldCreatePaymentAndStoreOnlyScalarDetails(): void
    {
        $payment = $this->createPayment();

        $token = new Token();
        $token->setAfterUrl('theContinueUrl');
        $token->setGatewayName('quickpay');

        $convert = new Convert($payment, 'array', $token);

        $this->queuePayment(['id' => 1001, 'order_id' => 'ut000000000001']);

        $action = new ConvertPaymentAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);
        $action->execute($convert);

        /** @var array<string, mixed> $result */
        $result = $convert->getResult();

        self::assertSame(1001, $result['quickpayPaymentId']);
        self::assertSame(100, $result['amount']);
        self::assertSame('DKK', $result['currency']);
        self::assertSame('ut000000000001', $result['order_id']);
        self::assertSame('theContinueUrl', $result['continue_url']);
        self::assertSame('theContinueUrl', $result['cancel_url']);

        // Only scalars may be persisted — no SDK DTO or Payum model object leaks into the details.
        self::assertArrayNotHasKey('quickpayPayment', $result);
        self::assertArrayNotHasKey('payment', $result);
        foreach ($result as $value) {
            self::assertIsNotObject($value, 'Details must not contain objects');
        }

        // The create request carries only order_id + currency.
        $requests = $this->getRequests();
        self::assertCount(1, $requests);
        $this->assertRequest($requests[0], 'POST', '#/payments$#');
        $body = $this->decodeBody($requests[0]);
        self::assertSame('ut000000000001', $body['order_id']);
        self::assertSame('DKK', $body['currency']);
        self::assertArrayNotHasKey('card', $body);
        self::assertArrayNotHasKey('payment', $body);
    }

    /**
     * @test
     */
    public function shouldThrowWhenCreatingAPaymentWithoutACurrency(): void
    {
        $payment = new Payment();
        $payment->setNumber('000000000001');
        $payment->setTotalAmount(100);

        $convert = new Convert($payment, 'array');

        $action = new ConvertPaymentAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"000000000001": it has no currency code');

        try {
            $action->execute($convert);
        } finally {
            self::assertCount(0, $this->getRequests(), 'The create request must not be issued');
        }
    }

    /**
     * A missing number would not throw on its own — it is concatenated with the order prefix, so it
     * degrades silently into an order id that is nothing but the prefix.
     *
     * @test
     */
    public function shouldThrowWhenCreatingAPaymentWithoutANumber(): void
    {
        $payment = new Payment();
        $payment->setTotalAmount(100);
        $payment->setCurrencyCode('DKK');

        $convert = new Convert($payment, 'array');

        $action = new ConvertPaymentAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('it has no number');

        try {
            $action->execute($convert);
        } finally {
            self::assertCount(0, $this->getRequests(), 'The create request must not be issued');
        }
    }

    /**
     * @test
     *
     * @dataProvider outOfRangeOrderIdProvider
     */
    public function shouldThrowWhenTheOrderIdIsOutsideQuickpaysLength(string $number, string $expected): void
    {
        $payment = new Payment();
        $payment->setNumber($number);
        $payment->setTotalAmount(100);
        $payment->setCurrencyCode('DKK');

        $convert = new Convert($payment, 'array');

        $action = new ConvertPaymentAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($expected);

        try {
            $action->execute($convert);
        } finally {
            self::assertCount(0, $this->getRequests(), 'The create request must not be issued');
        }
    }

    /**
     * The api under test carries the order prefix "ut", so the number contributes the rest.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function outOfRangeOrderIdProvider(): iterable
    {
        yield 'one under the minimum' => ['1', 'The Quickpay order id "ut1" is 3 characters'];
        yield 'one over the maximum' => [str_repeat('9', 19), 'is 21 characters'];
    }

    /**
     * @test
     */
    public function shouldAcceptOrderIdsAtBothEndsOfTheRange(): void
    {
        // "ut" + 2 = 4 characters, the minimum; and "ut" + 18 = 20, the maximum.
        foreach (['12' => 'ut12', str_repeat('9', 18) => 'ut' . str_repeat('9', 18)] as $number => $expectedOrderId) {
            $payment = new Payment();
            $payment->setNumber((string) $number);
            $payment->setTotalAmount(100);
            $payment->setCurrencyCode('DKK');

            $convert = new Convert($payment, 'array');

            $action = new ConvertPaymentAction();
            $action->setGateway($this->gateway);
            $action->setApi($this->api);

            $this->queuePayment(['id' => 1001, 'order_id' => $expectedOrderId]);

            $action->execute($convert);

            $requests = $this->getRequests();
            self::assertSame($expectedOrderId, $this->decodeBody($requests[array_key_last($requests)])['order_id']);
        }
    }

    /**
     * A Quickpay payment's currency is fixed at creation — the authorize happens in that currency no
     * matter what the details say. Overwriting the stored currency, as the action used to, let the
     * shop believe one currency while Quickpay kept charging in the other.
     *
     * @test
     */
    public function shouldThrowWhenTheCurrencyChangedAfterCreation(): void
    {
        $payment = $this->createPayment();
        $payment->setCurrencyCode('EUR');
        $payment->setDetails(['quickpayPaymentId' => 555, 'currency' => 'DKK']);

        $convert = new Convert($payment, 'array');

        $action = new ConvertPaymentAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('cannot change currency');

        try {
            $action->execute($convert);
        } finally {
            self::assertCount(0, $this->getRequests(), 'No request may be issued for a drifted payment');
        }
    }

    /**
     * Payum's model allows a null currency. That is nothing to compare against, so the stored value
     * — the one the Quickpay payment was actually created with — must survive instead of being
     * overwritten with null.
     *
     * @test
     */
    public function shouldKeepTheStoredCurrencyWhenTheModelCarriesNone(): void
    {
        $payment = new Payment();
        $payment->setNumber('000000000001');
        $payment->setTotalAmount(100);
        $payment->setDetails(['quickpayPaymentId' => 555, 'currency' => 'DKK']);

        $convert = new Convert($payment, 'array');

        $action = new ConvertPaymentAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);
        $action->execute($convert);

        /** @var array<string, mixed> $result */
        $result = $convert->getResult();

        self::assertSame('DKK', $result['currency']);
        self::assertCount(0, $this->getRequests());
    }

    /**
     * @test
     */
    public function shouldNotCreateAgainWhenPaymentAlreadyExists(): void
    {
        $payment = $this->createPayment();
        $payment->setDetails(['quickpayPaymentId' => 555]);

        $token = new Token();
        $token->setAfterUrl('theContinueUrl');

        $convert = new Convert($payment, 'array', $token);

        $action = new ConvertPaymentAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);
        $action->execute($convert);

        /** @var array<string, mixed> $result */
        $result = $convert->getResult();

        self::assertSame(555, $result['quickpayPaymentId']);
        self::assertCount(0, $this->getRequests(), 'No create request should be issued');
    }
}
