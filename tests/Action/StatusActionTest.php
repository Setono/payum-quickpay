<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Tests\Action;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Request\GetHumanStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Setono\Payum\Quickpay\Action\StatusAction;
use Setono\Quickpay\Enum\OperationType;
use Setono\Quickpay\Enum\PaymentState;

class StatusActionTest extends ActionTestAbstract
{
    protected static string $requestClass = GetHumanStatus::class;

    protected static string $actionClass = StatusAction::class;

    #[Test]
    public function shouldMarkEmptyAsNew(): void
    {
        $request = new GetHumanStatus([]);

        $action = new StatusAction();
        $action->execute($request);

        self::assertTrue($request->isNew(), 'Request should be marked as new');
    }

    /**
     * A null id is "not created yet" — the value a consumer's own Convert or a storage round trip may
     * leave behind, and what ConvertPaymentAction itself reads as absent. It must answer `new` (so a
     * Sylius checkout goes on to Convert) rather than throw "execute Convert first" at the caller.
     */
    #[Test]
    public function shouldMarkANullIdAsNew(): void
    {
        $request = new GetHumanStatus([]);
        $request->setModel(new ArrayObject(['quickpayPaymentId' => null, 'amount' => 100]));

        $this->executeStatus($request);

        self::assertTrue($request->isNew(), 'Request should be marked as new');
        self::assertCount(0, $this->getRequests());
    }

    #[Test]
    public function shouldMarkInitialAsNew(): void
    {
        $this->queuePayment(['state' => PaymentState::Initial->value]);

        $request = $this->statusRequest();
        $this->executeStatus($request);

        self::assertTrue($request->isNew(), 'Request should be marked as new');
    }

    #[Test]
    public function shouldMarkNewWithApprovedAuthorizeAsAuthorized(): void
    {
        $this->queuePayment([
            'state' => PaymentState::New->value,
            'operations' => [$this->operation(OperationType::Authorize)],
        ]);

        $request = $this->statusRequest();
        $this->executeStatus($request);

        self::assertTrue($request->isAuthorized(), 'Request should be marked as authorized');
    }

    #[Test]
    public function shouldMarkNewWithRejectedAuthorizeAsFailed(): void
    {
        $this->queuePayment([
            'state' => PaymentState::New->value,
            'operations' => [$this->operation(OperationType::Authorize, '40000')],
        ]);

        $request = $this->statusRequest();
        $this->executeStatus($request);

        self::assertTrue($request->isFailed(), 'Request should be marked as failed');
    }

    /**
     * `pending` with nothing approved yet — no operations, an authorize held up in 3-D Secure, a
     * declined attempt with a retry in flight — is genuinely pending.
     *
     * @param list<array<string, mixed>> $operations
     */
    #[Test]
    #[DataProvider('genuinelyPendingProvider')]
    public function shouldMarkPendingAsPending(array $operations): void
    {
        $this->queuePayment(['state' => PaymentState::Pending->value, 'operations' => $operations]);

        $request = $this->statusRequest();
        $this->executeStatus($request);

        self::assertSame($request::STATUS_PENDING, $request->getValue());
    }

    /**
     * @return iterable<string, array{list<array<string, mixed>>}>
     */
    public static function genuinelyPendingProvider(): iterable
    {
        yield 'no operations' => [[]];
        yield 'authorize in flight (3-D Secure)' => [[['id' => 1, 'type' => 'authorize', 'amount' => 100, 'pending' => true, 'qp_status_code' => null]]];
        yield 'declined authorize, retry in flight' => [[
            ['id' => 1, 'type' => 'authorize', 'amount' => 100, 'pending' => false, 'qp_status_code' => '40000'],
            ['id' => 2, 'type' => 'authorize', 'amount' => 100, 'pending' => true, 'qp_status_code' => null],
        ]];
    }

    /**
     * `pending` is Quickpay's state whenever an operation is in flight — including an asynchronous
     * capture, refund or cancel on a payment that already has money captured or held (verified live,
     * 2026-08: a captured payment reads `pending` for the ~second its refund takes). An operation in
     * flight never changes what already happened, so what has been approved decides: the same rule
     * as `processed`.
     *
     * @param list<array<string, mixed>> $operations
     */
    #[Test]
    #[DataProvider('pendingWithHistoryProvider')]
    public function shouldDecidePendingFromWhatAlreadyHappened(array $operations, ?int $balance, string $expected): void
    {
        $this->queuePayment(['state' => PaymentState::Pending->value, 'balance' => $balance, 'operations' => $operations]);

        $request = $this->statusRequest();
        $this->executeStatus($request);

        self::assertSame($expected, $request->getValue());
    }

    /**
     * @return iterable<string, array{list<array<string, mixed>>, int|null, string}>
     */
    public static function pendingWithHistoryProvider(): iterable
    {
        $authorize = ['id' => 1, 'type' => 'authorize', 'amount' => 1000, 'pending' => false, 'qp_status_code' => '20000'];
        $capture = ['id' => 2, 'type' => 'capture', 'amount' => 1000, 'pending' => false, 'qp_status_code' => '20000'];
        $pendingCapture = ['id' => 2, 'type' => 'capture', 'amount' => 1000, 'pending' => true, 'qp_status_code' => null];
        $pendingRefund = ['id' => 3, 'type' => 'refund', 'amount' => 250, 'pending' => true, 'qp_status_code' => null];
        $pendingCancel = ['id' => 2, 'type' => 'cancel', 'amount' => 1000, 'pending' => true, 'qp_status_code' => null];

        yield 'authorized, capture in flight (auto-capture return trip)' => [[$authorize, $pendingCapture], 0, GetHumanStatus::STATUS_AUTHORIZED];
        yield 'authorized, cancel in flight' => [[$authorize, $pendingCancel], 0, GetHumanStatus::STATUS_AUTHORIZED];
        yield 'captured, refund in flight' => [[$authorize, $capture, $pendingRefund], 1000, GetHumanStatus::STATUS_CAPTURED];
        yield 'partially refunded, another refund in flight' => [[
            $authorize, $capture,
            ['id' => 3, 'type' => 'refund', 'amount' => 250, 'pending' => false, 'qp_status_code' => '20000'],
            ['id' => 4, 'type' => 'refund', 'amount' => 250, 'pending' => true, 'qp_status_code' => null],
        ], 750, GetHumanStatus::STATUS_CAPTURED];
    }

    #[Test]
    public function shouldMarkRejectedAsFailed(): void
    {
        $this->queuePayment(['state' => PaymentState::Rejected->value]);

        $request = $this->statusRequest();
        $this->executeStatus($request);

        self::assertTrue($request->isFailed(), 'Request should be marked as failed');
    }

    #[Test]
    public function shouldMarkInvalidAsFailed(): void
    {
        $this->queuePayment(['state' => PaymentState::Invalid->value]);

        $request = $this->statusRequest();
        $this->executeStatus($request);

        self::assertTrue($request->isFailed(), 'Request should be marked as failed');
    }

    #[Test]
    public function shouldMarkProcessedWithCaptureAsCaptured(): void
    {
        $this->queuePayment([
            'state' => PaymentState::Processed->value,
            'operations' => [$this->operation(OperationType::Capture)],
        ]);

        $request = $this->statusRequest();
        $this->executeStatus($request);

        self::assertTrue($request->isCaptured(), 'Request should be marked as captured');
    }

    #[Test]
    public function shouldMarkProcessedWithRefundAsRefundedWhenTheBalanceIsEmpty(): void
    {
        $this->queuePayment([
            'state' => PaymentState::Processed->value,
            'balance' => 0,
            'operations' => [$this->operation(OperationType::Refund)],
        ]);

        $request = $this->statusRequest();
        $this->executeStatus($request);

        self::assertSame($request::STATUS_REFUNDED, $request->getValue());
    }

    /**
     * A partial refund leaves money captured, and Payum has no partial mark — so the payment is still
     * captured, not refunded. Reporting it as refunded would tell a shop the customer got everything
     * back when most of it is still held.
     */
    #[Test]
    public function shouldMarkProcessedWithAPartialRefundAsStillCaptured(): void
    {
        $this->queuePayment([
            'state' => PaymentState::Processed->value,
            // Captured 1000, refunded 250, so 750 is still held.
            'balance' => 750,
            'operations' => [
                $this->operation(OperationType::Capture, amount: 1000),
                $this->operation(OperationType::Refund, amount: 250),
            ],
        ]);

        $request = $this->statusRequest();
        $this->executeStatus($request);

        self::assertSame($request::STATUS_CAPTURED, $request->getValue());
    }

    /**
     * `balance` is nullable in the API. Absent, fall back to treating a refund as full rather than
     * inventing a number.
     */
    #[Test]
    public function shouldMarkProcessedWithRefundAsRefundedWhenTheBalanceIsAbsent(): void
    {
        $this->queuePayment([
            'state' => PaymentState::Processed->value,
            'operations' => [$this->operation(OperationType::Refund)],
        ]);

        $request = $this->statusRequest();
        $this->executeStatus($request);

        self::assertSame($request::STATUS_REFUNDED, $request->getValue());
    }

    /**
     * A trailing REJECTED attempt must not mask what actually happened: capture 1000, then a refund
     * the acquirer bounced — the 1000 is demonstrably still held, so the payment is still captured,
     * not unknown.
     */
    #[Test]
    public function shouldStayCapturedWhenARefundAttemptWasRejected(): void
    {
        $this->queuePayment([
            'state' => PaymentState::Processed->value,
            'balance' => 1000,
            'operations' => [
                $this->operation(OperationType::Capture, amount: 1000),
                $this->operation(OperationType::Refund, '40000', amount: 250),
            ],
        ]);

        $request = $this->statusRequest();
        $this->executeStatus($request);

        self::assertSame($request::STATUS_CAPTURED, $request->getValue());
    }

    /**
     * Same for a PENDING attempt: an asynchronous refund that has not settled yet has no outcome,
     * so the status keeps reporting the last operation that did succeed.
     */
    #[Test]
    public function shouldStayCapturedWhileARefundIsStillPending(): void
    {
        $this->queuePayment([
            'state' => PaymentState::Processed->value,
            'balance' => 1000,
            'operations' => [
                $this->operation(OperationType::Capture, amount: 1000),
                $this->operation(OperationType::Refund, null, amount: 250, pending: true),
            ],
        ]);

        $request = $this->statusRequest();
        $this->executeStatus($request);

        self::assertSame($request::STATUS_CAPTURED, $request->getValue());
    }

    /**
     * And in the `new` state: an authorized payment whose capture attempt was rejected is still
     * authorized — the money is still held and a retry is possible. It must not report as failed.
     */
    #[Test]
    public function shouldStayAuthorizedWhenACaptureAttemptWasRejected(): void
    {
        $this->queuePayment([
            'state' => PaymentState::New->value,
            'operations' => [
                $this->operation(OperationType::Authorize, amount: 1000),
                $this->operation(OperationType::Capture, '40000', amount: 1000),
            ],
        ]);

        $request = $this->statusRequest();
        $this->executeStatus($request);

        self::assertTrue($request->isAuthorized(), 'Request should be marked as authorized');
    }

    #[Test]
    public function shouldMarkNewWithoutAnyOperationsAsFailed(): void
    {
        $this->queuePayment(['state' => PaymentState::New->value, 'operations' => []]);

        $request = $this->statusRequest();
        $this->executeStatus($request);

        self::assertTrue($request->isFailed(), 'Request should be marked as failed');
    }

    /**
     * The SDK's PaymentState enum is non-exhaustive by design — Quickpay may grow states. An
     * unmodeled state maps to unknown rather than anything more confident.
     */
    #[Test]
    public function shouldMarkAnUnmodeledStateAsUnknown(): void
    {
        $this->queuePayment(['state' => 'some_future_state']);

        $request = $this->statusRequest();
        // A fresh GetHumanStatus already reads `unknown`, so pre-mark it: the action must actively say so.
        $request->markNew();
        $this->executeStatus($request);

        self::assertSame($request::STATUS_UNKNOWN, $request->getValue());
    }

    #[Test]
    public function shouldMarkProcessedWithCancelAsCanceled(): void
    {
        $this->queuePayment([
            'state' => PaymentState::Processed->value,
            'operations' => [$this->operation(OperationType::Cancel)],
        ]);

        $request = $this->statusRequest();
        $this->executeStatus($request);

        self::assertSame($request::STATUS_CANCELED, $request->getValue());
    }

    #[Test]
    public function shouldMarkProcessedWithoutApprovedOperationAsUnknown(): void
    {
        $this->queuePayment([
            'state' => PaymentState::Processed->value,
            'operations' => [$this->operation(OperationType::Capture, '40000')],
        ]);

        $request = $this->statusRequest();
        // A fresh GetHumanStatus already reads `unknown`, so pre-mark it: the action must actively say so.
        $request->markNew();
        $this->executeStatus($request);

        self::assertSame($request::STATUS_UNKNOWN, $request->getValue());
    }

    /**
     * The payment is fetched anyway to decide the status, so the balance it carries is written back
     * into the details — it is the number the Payum marks cannot express, and re-fetching it downstream
     * would cost another API call.
     */
    #[Test]
    public function shouldPersistTheBalanceIntoTheDetails(): void
    {
        $this->queuePayment([
            'state' => PaymentState::Processed->value,
            'balance' => 750,
            'operations' => [
                $this->operation(OperationType::Capture, amount: 1000),
                $this->operation(OperationType::Refund, amount: 250),
            ],
        ]);

        $request = $this->statusRequest();
        $this->executeStatus($request);

        /** @var ArrayObject<string, mixed> $details */
        $details = $request->getModel();

        self::assertSame(750, $details['balance']);
    }

    private function statusRequest(): GetHumanStatus
    {
        $request = new GetHumanStatus([]);
        $request->setModel(new ArrayObject(['quickpayPaymentId' => 1001]));

        return $request;
    }

    private function executeStatus(GetHumanStatus $request): void
    {
        $action = new StatusAction();
        $action->setApi($this->api);
        $action->execute($request);
    }
}
