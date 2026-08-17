<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Tests\Action\Api;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\LogicException;
use PHPUnit\Framework\TestCase;
use Setono\Payum\Quickpay\Action\Api\ConfirmPaymentAction;
use Setono\Payum\Quickpay\Request\Api\ConfirmPayment;
use Setono\Payum\Quickpay\Tests\ApiTestTrait;
use Setono\Quickpay\Enum\OperationType;
use Setono\Quickpay\Enum\PaymentState;

class ConfirmPaymentActionTest extends TestCase
{
    use ApiTestTrait;

    /**
     * @test
     */
    public function shouldThrowWhenPaymentHasNotBeenCreated(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('quickpayPaymentId');

        $this->action()->execute(new ConfirmPayment(new ArrayObject(['amount' => 100])));
    }

    /**
     * The callback is the moment the payment changed, so the scalar snapshot is refreshed from it —
     * the same keys Sync writes.
     *
     * @test
     */
    public function shouldRefreshTheScalarSnapshot(): void
    {
        $this->queuePayment([
            'state' => PaymentState::Processed->value,
            'balance' => 750,
            'operations' => [$this->operation(OperationType::Refund, amount: 250)],
        ]);

        $details = new ArrayObject(['quickpayPaymentId' => 1001, 'amount' => 1000]);

        $this->action()->execute(new ConfirmPayment($details));

        self::assertSame(750, $details['balance']);
        self::assertSame(PaymentState::Processed->value, $details['state']);

        $requests = $this->getRequests();
        self::assertCount(1, $requests);
        $this->assertRequest($requests[0], 'GET', '#/payments/1001$#');
    }

    /**
     * A callback can arrive for a payment with no operations yet — Quickpay fires one when the
     * payment is merely created, visible as soon as an account-wide callback url is configured. It
     * must be a quiet no-op: throwing would 500 the notify endpoint and have Quickpay retry forever.
     *
     * @test
     */
    public function shouldCompleteQuietlyForAPaymentWithoutOperations(): void
    {
        $this->queuePayment(['state' => PaymentState::Initial->value, 'operations' => []]);

        $details = new ArrayObject(['quickpayPaymentId' => 1001, 'amount' => 100]);
        $this->action()->execute(new ConfirmPayment($details));

        self::assertCount(1, $this->getRequests());
        self::assertSame(PaymentState::Initial->value, $details['state']);
    }

    /**
     * The callback path never moves money — not even for an approved authorize with the (deprecated)
     * auto_capture option on. Capturing on authorization is the payment LINK's job (its own
     * auto_capture flag, set by CaptureAction); a second capture from here would only race it.
     *
     * @test
     *
     * @dataProvider authorizeCallbackProvider
     *
     * @param array<string, mixed> $authorize
     */
    public function shouldNeverCaptureFromACallback(string $state, array $authorize): void
    {
        $this->queuePayment([
            'state' => $state,
            'operations' => [$authorize],
            'link' => $this->link(autoCapture: true),
        ]);

        // The api under test has auto_capture ON — the setting that used to trigger a capture here.
        $this->action()->execute(new ConfirmPayment(new ArrayObject(['quickpayPaymentId' => 1001, 'amount' => 100])));

        $requests = $this->getRequests();
        self::assertCount(1, $requests, 'Only the fetch — no capture may be issued from the callback path');
        $this->assertRequest($requests[0], 'GET', '#/payments/1001$#');
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function authorizeCallbackProvider(): iterable
    {
        yield 'approved authorize' => [PaymentState::New->value, ['id' => 1, 'type' => 'authorize', 'amount' => 100, 'pending' => false, 'qp_status_code' => '20000']];
        yield 'rejected authorize' => [PaymentState::Rejected->value, ['id' => 1, 'type' => 'authorize', 'amount' => 100, 'pending' => false, 'qp_status_code' => '40000']];
        yield 'pending authorize' => [PaymentState::Pending->value, ['id' => 1, 'type' => 'authorize', 'amount' => 100, 'pending' => true, 'qp_status_code' => null]];
    }

    private function action(): ConfirmPaymentAction
    {
        $action = new ConfirmPaymentAction();
        $action->setApi($this->api);
        $action->setGateway($this->gateway);

        return $action;
    }
}
