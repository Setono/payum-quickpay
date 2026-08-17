<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Tests\Action;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Request\GetHumanStatus;
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

    #[Test]
    public function shouldMarkPendingAsPending(): void
    {
        $this->queuePayment(['state' => PaymentState::Pending->value]);

        $request = $this->statusRequest();
        $this->executeStatus($request);

        self::assertSame($request::STATUS_PENDING, $request->getValue());
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
