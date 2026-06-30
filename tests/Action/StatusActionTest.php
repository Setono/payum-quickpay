<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Tests\Action;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Request\GetHumanStatus;
use Setono\Payum\Quickpay\Action\StatusAction;
use Setono\Quickpay\Enum\OperationType;
use Setono\Quickpay\Enum\PaymentState;

class StatusActionTest extends ActionTestAbstract
{
    protected $requestClass = GetHumanStatus::class;

    protected $actionClass = StatusAction::class;

    /**
     * @test
     */
    public function shouldMarkEmptyAsNew(): void
    {
        $request = new GetHumanStatus([]);

        $action = new StatusAction();
        $action->execute($request);

        self::assertTrue($request->isNew(), 'Request should be marked as new');
    }

    /**
     * @test
     */
    public function shouldMarkInitialAsNew(): void
    {
        $this->queuePayment(['state' => PaymentState::Initial->value]);

        $request = $this->statusRequest();
        $this->executeStatus($request);

        self::assertTrue($request->isNew(), 'Request should be marked as new');
    }

    /**
     * @test
     */
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

    /**
     * @test
     */
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
     * @test
     */
    public function shouldMarkPendingAsPending(): void
    {
        $this->queuePayment(['state' => PaymentState::Pending->value]);

        $request = $this->statusRequest();
        $this->executeStatus($request);

        self::assertSame($request::STATUS_PENDING, $request->getValue());
    }

    /**
     * @test
     */
    public function shouldMarkRejectedAsFailed(): void
    {
        $this->queuePayment(['state' => PaymentState::Rejected->value]);

        $request = $this->statusRequest();
        $this->executeStatus($request);

        self::assertTrue($request->isFailed(), 'Request should be marked as failed');
    }

    /**
     * @test
     */
    public function shouldMarkInvalidAsFailed(): void
    {
        $this->queuePayment(['state' => PaymentState::Invalid->value]);

        $request = $this->statusRequest();
        $this->executeStatus($request);

        self::assertTrue($request->isFailed(), 'Request should be marked as failed');
    }

    /**
     * @test
     */
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

    /**
     * @test
     */
    public function shouldMarkProcessedWithRefundAsRefunded(): void
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
     * @test
     */
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

    /**
     * @test
     */
    public function shouldMarkProcessedWithoutApprovedOperationAsUnknown(): void
    {
        $this->queuePayment([
            'state' => PaymentState::Processed->value,
            'operations' => [$this->operation(OperationType::Capture, '40000')],
        ]);

        $request = $this->statusRequest();
        $this->executeStatus($request);

        self::assertSame($request::STATUS_UNKNOWN, $request->getValue());
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
