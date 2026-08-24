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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Setono\Payum\Quickpay\Action\ConvertPaymentAction;
use Setono\Payum\Quickpay\Tests\ApiTestTrait;
use Setono\Quickpay\Enum\PaymentState;
use stdClass;

/**
 * {@see Convert} is not a {@see \Payum\Core\Request\Generic} and has a three-argument constructor, so
 * this test cannot use the generic {@see GenericActionTestCase} providers and stands on its own.
 */
class ConvertPaymentActionTest extends TestCase
{
    use ApiTestTrait;

    #[Test]
    public function shouldImplementExpectedInterfaces(): void
    {
        $action = new ConvertPaymentAction();

        self::assertInstanceOf(ActionInterface::class, $action);
        self::assertInstanceOf(ApiAwareInterface::class, $action);
        self::assertInstanceOf(GatewayAwareInterface::class, $action);
    }

    #[Test]
    public function shouldSupportConvertingAPaymentToArray(): void
    {
        $action = new ConvertPaymentAction();

        self::assertTrue($action->supports(new Convert(new Payment(), 'array')));
        self::assertFalse($action->supports(new Convert(new Payment(), 'json')));
        self::assertFalse($action->supports(new Convert(new stdClass(), 'array')));
        self::assertFalse($action->supports('foo'));
    }

    #[Test]
    public function shouldCreatePaymentAndStoreOnlyScalarDetails(): void
    {
        $payment = $this->createPayment();

        $token = new Token();
        $token->setTargetUrl('theTargetUrl');
        $token->setAfterUrl('theAfterUrl');
        $token->setGatewayName('quickpay');

        $convert = new Convert($payment, 'array', $token);

        // The lookup (nothing under that order id yet), then the create.
        $this->queueResponse('[]');
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
        // The customer returns to the token's TARGET url so the Authorize/Capture that sent them out
        // re-executes and finishes the job (Payum's return-trip convention); a cancel goes straight
        // to the after url, since nothing was done that needs finishing.
        self::assertSame('theTargetUrl', $result['continue_url']);
        self::assertSame('theAfterUrl', $result['cancel_url']);

        // Only scalars may be persisted — no SDK DTO or Payum model object leaks into the details.
        self::assertArrayNotHasKey('quickpayPayment', $result);
        self::assertArrayNotHasKey('payment', $result);
        foreach ($result as $value) {
            self::assertIsNotObject($value, 'Details must not contain objects');
        }

        // Find-or-create: an exact order_id lookup first, then the create — which carries only
        // order_id + currency (+ the shopsystem).
        $requests = $this->getRequests();
        self::assertCount(2, $requests);
        $this->assertRequest($requests[0], 'GET', '#/payments$#');
        self::assertSame('order_id=ut000000000001&page=1&page_size=1', urldecode($requests[0]->getUri()->getQuery()));
        $this->assertRequest($requests[1], 'POST', '#/payments$#');
        $body = $this->decodeBody($requests[1]);
        self::assertSame('ut000000000001', $body['order_id']);
        self::assertSame('DKK', $body['currency']);
        self::assertArrayNotHasKey('card', $body);
        self::assertArrayNotHasKey('payment', $body);
        // The integration identifies itself: Quickpay records `shopsystem` on the payment.
        self::assertSame('setono/payum-quickpay', $body['shopsystem']['name']);
        self::assertIsString($body['shopsystem']['version']);
        self::assertNotSame('', $body['shopsystem']['version']);
    }

    #[Test]
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
     */
    #[Test]
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

    #[Test]
    #[DataProvider('outOfRangeOrderIdProvider')]
    public function shouldThrowWhenTheOrderIdIsNotOneQuickpayAccepts(string $number, string $expected): void
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
     * The api under test carries the order prefix "ut", so the number contributes the rest. The rule is
     * the SDK's (verified live): 4–20 characters of letters, digits, space, ".", "_" and "-" — so a
     * character Quickpay rejects fails here too, before the lookup, naming the prefix and number.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function outOfRangeOrderIdProvider(): iterable
    {
        yield 'one under the minimum' => ['1', 'The Quickpay order id "ut1" (3 characters) is not one Quickpay accepts'];
        yield 'one over the maximum' => [str_repeat('9', 19), '(21 characters) is not one Quickpay accepts'];
        yield 'a character Quickpay rejects' => ['12/34', 'The Quickpay order id "ut12/34" (7 characters) is not one Quickpay accepts'];
    }

    #[Test]
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

            $this->queueResponse('[]');
            $this->queuePayment(['id' => 1001, 'order_id' => $expectedOrderId]);

            $action->execute($convert);

            $requests = $this->getRequests();
            self::assertSame($expectedOrderId, $this->decodeBody($requests[array_key_last($requests)])['order_id']);
        }
    }

    // -- Find-or-create ----------------------------------------------------------------------

    /**
     * Quickpay enforces order_id uniqueness per account (a second create is a 400 "order_id already
     * exists on another payment" — verified live), and under Sylius the Payum payment number is the
     * ORDER number, so a customer who was declined and pays again carries the same order id. A payment
     * that already exists under it and was never successfully paid — the window never completed, or the
     * attempt was declined — is picked up where it was left instead of the create failing.
     *
     * @param array<string, mixed> $existing
     */
    #[Test]
    #[DataProvider('reusablePaymentProvider')]
    public function shouldPickUpAnExistingPaymentNobodyHasPaid(array $existing): void
    {
        $payment = $this->createPayment();
        $convert = new Convert($payment, 'array');

        $this->queueResponse('[' . $this->paymentJson(array_replace(['id' => 4242, 'order_id' => 'ut000000000001'], $existing)) . ']');

        $action = new ConvertPaymentAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);
        $action->execute($convert);

        /** @var array<string, mixed> $result */
        $result = $convert->getResult();

        self::assertSame(4242, $result['quickpayPaymentId']);
        self::assertSame('ut000000000001', $result['order_id']);
        self::assertSame('DKK', $result['currency']);

        $requests = $this->getRequests();
        self::assertCount(1, $requests, 'Only the lookup — nothing is created');
        $this->assertRequest($requests[0], 'GET', '#/payments$#');
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function reusablePaymentProvider(): iterable
    {
        yield 'created, window never completed' => [['state' => PaymentState::Initial->value, 'operations' => []]];
        yield 'declined attempt' => [['state' => PaymentState::Rejected->value, 'operations' => [
            ['id' => 1, 'type' => 'authorize', 'amount' => 100, 'pending' => false, 'qp_status_code' => '40000'],
        ]]];
        yield 'authorize still in flight (3-D Secure in another tab)' => [['state' => PaymentState::Pending->value, 'operations' => [
            ['id' => 1, 'type' => 'authorize', 'amount' => 100, 'pending' => true, 'qp_status_code' => null],
        ]]];
    }

    /**
     * A payment that HAS an approved operation is never adopted silently: it may be this order's
     * earlier payment that really was paid, or another environment's payment under a prefix that
     * should not be shared. Either way that is the shop's call, so it is a clear exception — never a
     * claim of money.
     *
     * @param list<array<string, mixed>> $operations
     */
    #[Test]
    #[DataProvider('paidPaymentProvider')]
    public function shouldRefuseToAdoptAnExistingPaymentThatWasPaid(string $state, array $operations, string $expectedType): void
    {
        $convert = new Convert($this->createPayment(), 'array');

        $this->queueResponse('[' . $this->paymentJson(['id' => 4242, 'order_id' => 'ut000000000001', 'state' => $state, 'operations' => $operations]) . ']');

        $action = new ConvertPaymentAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(sprintf('already exists (id 4242, state %s) and has an approved %s', $state, $expectedType));

        try {
            $action->execute($convert);
        } finally {
            self::assertCount(1, $this->getRequests(), 'Only the lookup — nothing is created');
        }
    }

    /**
     * @return iterable<string, array{string, list<array<string, mixed>>, string}>
     */
    public static function paidPaymentProvider(): iterable
    {
        $authorize = ['id' => 1, 'type' => 'authorize', 'amount' => 100, 'pending' => false, 'qp_status_code' => '20000'];

        yield 'authorized' => [PaymentState::New->value, [$authorize], 'authorize'];
        yield 'captured' => [PaymentState::Processed->value, [$authorize, ['id' => 2, 'type' => 'capture', 'amount' => 100, 'pending' => false, 'qp_status_code' => '20000']], 'capture'];
        yield 'cancelled' => [PaymentState::Processed->value, [$authorize, ['id' => 2, 'type' => 'cancel', 'amount' => 100, 'pending' => false, 'qp_status_code' => '20000']], 'cancel'];
    }

    /**
     * The existing payment is what Quickpay charges in; a retry in another currency cannot reuse it.
     */
    #[Test]
    public function shouldRefuseToAdoptAnExistingPaymentInAnotherCurrency(): void
    {
        $convert = new Convert($this->createPayment(), 'array');

        $this->queueResponse('[' . $this->paymentJson(['id' => 4242, 'order_id' => 'ut000000000001', 'currency' => 'EUR', 'state' => PaymentState::Initial->value]) . ']');

        $action = new ConvertPaymentAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('already exists (id 4242) in EUR, but this payment is in DKK');

        try {
            $action->execute($convert);
        } finally {
            self::assertCount(1, $this->getRequests());
        }
    }

    /**
     * A Quickpay payment's currency is fixed at creation — the authorize happens in that currency no
     * matter what the details say. Overwriting the stored currency, as the action used to, let the
     * shop believe one currency while Quickpay kept charging in the other.
     */
    #[Test]
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
     */
    #[Test]
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

    #[Test]
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
