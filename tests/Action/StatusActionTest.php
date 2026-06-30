<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Tests\Action;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Request\GetHumanStatus;
use Setono\Payum\QuickPay\Action\StatusAction;
use Setono\Payum\QuickPay\Model\QuickPayPayment;
use Setono\Payum\QuickPay\Model\QuickPayPaymentOperation;

class StatusActionTest extends ActionTestAbstract
{
    protected $requestClass = GetHumanStatus::class;

    protected $actionClass = StatusAction::class;

    /**
     * @test
     */
    public function shouldMarkEmptyAsNew(): void
    {
        $statusRequest = new GetHumanStatus([]);

        $action = new StatusAction();
        $action->execute($statusRequest);
        self::assertTrue($statusRequest->isNew(), 'Request should be marked as new');
    }

    /**
     * @test
     */
    public function shouldMarkInitialAsNew(): void
    {
        $this->queuePayment(['id' => 1001, 'state' => QuickPayPayment::STATE_INITIAL]);
        $quickpayPayment = $this->api->getPayment(new ArrayObject(['payment' => $this->createPayment()]));

        $statusRequest = new GetHumanStatus([]);
        $statusRequest->setModel(new ArrayObject([
            'quickpayPaymentId' => $quickpayPayment->getId(),
        ]));

        $this->queuePayment(['id' => 1001, 'state' => QuickPayPayment::STATE_INITIAL]);
        $action = new StatusAction();
        $action->setApi($this->api);
        $action->execute($statusRequest);
        self::assertTrue($statusRequest->isNew(), 'Request should be marked as new');
    }

    /**
     * @test
     */
    public function shouldMarkNewAsAuthorized(): void
    {
        $this->queuePayment(['id' => 1001, 'state' => QuickPayPayment::STATE_INITIAL]);
        $quickpayPayment = $this->api->getPayment(new ArrayObject(['payment' => $this->createPayment()]));

        $this->queuePayment(['id' => 1001, 'state' => QuickPayPayment::STATE_NEW]);
        $this->api->authorizePayment($quickpayPayment, new ArrayObject([
            'card' => $this->getTestCard()->toArray(),
            'acquirer' => 'clearhaus',
            'amount' => 1,
        ]));

        $statusRequest = new GetHumanStatus([]);
        $statusRequest->setModel(new ArrayObject([
            'quickpayPaymentId' => $quickpayPayment->getId(),
        ]));

        $this->queuePayment([
            'id' => 1001,
            'state' => QuickPayPayment::STATE_NEW,
            'operations' => [$this->operation(QuickPayPaymentOperation::TYPE_AUTHORIZE)],
        ]);
        $action = new StatusAction();
        $action->setApi($this->api);
        $action->execute($statusRequest);
        self::assertTrue($statusRequest->isAuthorized(), 'Request should be marked as authorized');
    }

    /**
     * @test
     */
    public function shouldMarkNewAsFailed(): void
    {
        $this->queuePayment(['id' => 1001, 'state' => QuickPayPayment::STATE_INITIAL]);
        $quickpayPayment = $this->api->getPayment(new ArrayObject(['payment' => $this->createPayment()]));

        $this->queuePayment(['id' => 1001, 'state' => QuickPayPayment::STATE_NEW]);
        $this->api->authorizePayment($quickpayPayment, new ArrayObject([
            'card' => $this->getCaptureRejectedTestCard()->toArray(),
            'acquirer' => 'clearhaus',
            'amount' => 100,
        ]));

        $this->queuePayment(['id' => 1001, 'state' => QuickPayPayment::STATE_NEW]);
        $this->api->capturePayment($quickpayPayment, new ArrayObject([
            'amount' => 100,
        ]));

        $statusRequest = new GetHumanStatus([]);
        $statusRequest->setModel(new ArrayObject([
            'quickpayPaymentId' => $quickpayPayment->getId(),
        ]));

        // A new payment whose authorize operation was not approved -> failed.
        $this->queuePayment([
            'id' => 1001,
            'state' => QuickPayPayment::STATE_NEW,
            'operations' => [$this->operation(QuickPayPaymentOperation::TYPE_AUTHORIZE, 40000)],
        ]);
        $action = new StatusAction();
        $action->setApi($this->api);
        $action->execute($statusRequest);
        self::assertTrue($statusRequest->isFailed(), 'Request should be marked as failed');
    }

    /**
     * @test
     */
    public function shouldMarkPendingAsPending(): void
    {
        $this->queuePayment(['id' => 1001, 'state' => QuickPayPayment::STATE_INITIAL]);
        $quickpayPayment = $this->api->getPayment(new ArrayObject(['payment' => $this->createPayment()]));

        $this->queuePayment(['id' => 1001, 'state' => QuickPayPayment::STATE_PENDING]);
        $quickpayPayment = $this->api->authorizePayment($quickpayPayment, new ArrayObject([
            'card' => $this->getTestCard()->toArray(),
            'acquirer' => 'clearhaus',
            'amount' => 1,
        ]));
        self::assertEquals(QuickPayPayment::STATE_PENDING, $quickpayPayment->getState());

        $statusRequest = new GetHumanStatus([]);
        $statusRequest->setModel(new ArrayObject([
            'quickpayPayment' => $quickpayPayment,
        ]));

        $action = new StatusAction();
        $action->setApi($this->api);
        $action->execute($statusRequest);
        self::assertEquals($statusRequest::STATUS_PENDING, $statusRequest->getValue());
    }

    /**
     * @test
     */
    public function shouldMarkRejectedAsFailed(): void
    {
        $this->queuePayment(['id' => 1001, 'state' => QuickPayPayment::STATE_INITIAL]);
        $quickpayPayment = $this->api->getPayment(new ArrayObject(['payment' => $this->createPayment()]));

        $this->queuePayment(['id' => 1001, 'state' => QuickPayPayment::STATE_REJECTED]);
        $this->api->authorizePayment($quickpayPayment, new ArrayObject([
            'card' => $this->getAuthorizeRejectedTestCard()->toArray(),
            'acquirer' => 'clearhaus',
            'amount' => 1,
        ]));

        $this->queuePayment([
            'id' => 1001,
            'state' => QuickPayPayment::STATE_REJECTED,
            'operations' => [$this->operation(QuickPayPaymentOperation::TYPE_AUTHORIZE, 40000)],
        ]);
        $quickpayPayment = $this->api->getPayment(new ArrayObject([
            'quickpayPaymentId' => $quickpayPayment->getId(),
        ]));

        self::assertEquals(QuickPayPayment::STATE_REJECTED, $quickpayPayment->getState());
        self::assertEquals(QuickPayPaymentOperation::TYPE_AUTHORIZE, $quickpayPayment->getLatestOperation()->getType());

        $statusRequest = new GetHumanStatus([]);
        $statusRequest->setModel(new ArrayObject([
            'quickpayPayment' => $quickpayPayment,
        ]));

        $action = new StatusAction();
        $action->setApi($this->api);
        $action->execute($statusRequest);
        self::assertTrue($statusRequest->isFailed(), 'Request should be marked as failed');
    }

    /**
     * @test
     */
    public function shouldMarkProcessedAsCaptured(): void
    {
        $this->queuePayment(['id' => 1001, 'state' => QuickPayPayment::STATE_INITIAL]);
        $quickpayPayment = $this->api->getPayment(new ArrayObject(['payment' => $this->createPayment()]));

        $this->queuePayment(['id' => 1001, 'state' => QuickPayPayment::STATE_NEW]);
        $this->api->authorizePayment($quickpayPayment, new ArrayObject([
            'card' => $this->getTestCard()->toArray(),
            'acquirer' => 'clearhaus',
            'amount' => 100,
        ]));

        $this->queuePayment(['id' => 1001, 'state' => QuickPayPayment::STATE_PROCESSED]);
        $this->api->capturePayment($quickpayPayment, new ArrayObject([
            'amount' => 100,
        ]));

        $statusRequest = new GetHumanStatus([]);
        $statusRequest->setModel(new ArrayObject([
            'quickpayPaymentId' => $quickpayPayment->getId(),
        ]));

        $this->queuePayment([
            'id' => 1001,
            'state' => QuickPayPayment::STATE_PROCESSED,
            'operations' => [$this->operation(QuickPayPaymentOperation::TYPE_CAPTURE)],
        ]);
        $action = new StatusAction();
        $action->setApi($this->api);
        $action->execute($statusRequest);
        self::assertTrue($statusRequest->isCaptured(), 'Request should be marked as captured');
    }

    /**
     * @test
     */
    public function shouldMarkProcessedAsCanceled(): void
    {
        $this->queuePayment(['id' => 1001, 'state' => QuickPayPayment::STATE_INITIAL]);
        $quickpayPayment = $this->api->getPayment(new ArrayObject(['payment' => $this->createPayment()]));

        $this->queuePayment(['id' => 1001, 'state' => QuickPayPayment::STATE_NEW]);
        $this->api->authorizePayment($quickpayPayment, new ArrayObject([
            'card' => $this->getTestCard()->toArray(),
            'acquirer' => 'clearhaus',
            'amount' => 100,
        ]));

        $this->queuePayment(['id' => 1001, 'state' => QuickPayPayment::STATE_PROCESSED]);
        $this->api->cancelPayment($quickpayPayment, new ArrayObject([
            'amount' => 100,
        ]));

        $this->queuePayment([
            'id' => 1001,
            'state' => QuickPayPayment::STATE_PROCESSED,
            'operations' => [$this->operation(QuickPayPaymentOperation::TYPE_CANCEL)],
        ]);
        $quickpayPayment = $this->api->getPayment(new ArrayObject([
            'quickpayPaymentId' => $quickpayPayment->getId(),
        ]));

        self::assertEquals(QuickPayPayment::STATE_PROCESSED, $quickpayPayment->getState());
        self::assertEquals(QuickPayPaymentOperation::TYPE_CANCEL, $quickpayPayment->getLatestOperation()->getType());

        $statusRequest = new GetHumanStatus([]);
        $statusRequest->setModel(new ArrayObject([
            'quickpayPayment' => $quickpayPayment,
        ]));

        $action = new StatusAction();
        $action->setApi($this->api);
        $action->execute($statusRequest);
        self::assertTrue($statusRequest->isCanceled(), 'Request should be marked as canceled');
    }

    /**
     * @test
     */
    public function shouldMarkRefunded(): void
    {
        $this->queuePayment(['id' => 1001, 'state' => QuickPayPayment::STATE_INITIAL]);
        $quickpayPayment = $this->api->getPayment(new ArrayObject(['payment' => $this->createPayment()]));

        $this->queuePayment(['id' => 1001, 'state' => QuickPayPayment::STATE_NEW]);
        $this->api->authorizePayment($quickpayPayment, new ArrayObject([
            'card' => $this->getTestCard()->toArray(),
            'acquirer' => 'clearhaus',
            'amount' => 100,
        ]));

        $this->queuePayment(['id' => 1001, 'state' => QuickPayPayment::STATE_PROCESSED]);
        $this->api->capturePayment($quickpayPayment, new ArrayObject([
            'amount' => 100,
        ]));

        $this->queuePayment(['id' => 1001, 'state' => QuickPayPayment::STATE_PROCESSED]);
        $this->api->refundPayment($quickpayPayment, new ArrayObject([
            'amount' => 100,
        ]));

        $statusRequest = new GetHumanStatus([]);
        $statusRequest->setModel(new ArrayObject([
            'quickpayPaymentId' => $quickpayPayment->getId(),
        ]));

        $this->queuePayment([
            'id' => 1001,
            'state' => QuickPayPayment::STATE_PROCESSED,
            'operations' => [$this->operation(QuickPayPaymentOperation::TYPE_REFUND)],
        ]);
        $action = new StatusAction();
        $action->setApi($this->api);
        $action->execute($statusRequest);
        self::assertEquals($statusRequest::STATUS_REFUNDED, $statusRequest->getValue());
    }

    /**
     * @test
     */
    public function shouldMarkCanceled(): void
    {
        $this->queuePayment(['id' => 1001, 'state' => QuickPayPayment::STATE_INITIAL]);
        $quickpayPayment = $this->api->getPayment(new ArrayObject(['payment' => $this->createPayment()]));

        $this->queuePayment(['id' => 1001, 'state' => QuickPayPayment::STATE_NEW]);
        $this->api->authorizePayment($quickpayPayment, new ArrayObject([
            'card' => $this->getTestCard()->toArray(),
            'acquirer' => 'clearhaus',
            'amount' => 100,
        ]));

        $this->queuePayment(['id' => 1001, 'state' => QuickPayPayment::STATE_PROCESSED]);
        $this->api->cancelPayment($quickpayPayment, new ArrayObject([
            'amount' => 100,
        ]));

        $statusRequest = new GetHumanStatus([]);
        $statusRequest->setModel(new ArrayObject([
            'quickpayPaymentId' => $quickpayPayment->getId(),
        ]));

        $this->queuePayment([
            'id' => 1001,
            'state' => QuickPayPayment::STATE_PROCESSED,
            'operations' => [$this->operation(QuickPayPaymentOperation::TYPE_CANCEL)],
        ]);
        $action = new StatusAction();
        $action->setApi($this->api);
        $action->execute($statusRequest);
        self::assertEquals($statusRequest::STATUS_CANCELED, $statusRequest->getValue());
    }
}
