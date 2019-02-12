<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Tests\Action;

use Payum\Core\Bridge\Spl\ArrayObject;
use Setono\Payum\QuickPay\Action\StatusAction;
use Payum\Core\Request\GetHumanStatus;
use Setono\Payum\QuickPay\Model\QuickPayPayment;
use Setono\Payum\QuickPay\Model\QuickPayPaymentOperation;

class StatusActionTest extends ActionTestAbstract
{
    protected $requestClass = GetHumanStatus::class;

    protected $actionClass = StatusAction::class;

    /**
     * @test
     * @throws \Exception
     */
    public function shouldMarkEmptyAsNew(): void
    {
        $statusRequest = new GetHumanStatus([]);

        $action = new StatusAction();
        $action->execute($statusRequest);
        $this->assertTrue($statusRequest->isNew(), 'Request should be marked as new');
    }

    /**
     * @test
     * @throws \Exception
     */
    public function shouldMarkInitialAsNew(): void
    {
        $params = new ArrayObject([
            'payment' => $this->createPayment(),
        ]);
        $quickpayPayment = $this->api->getPayment($params);

        $statusRequest = new GetHumanStatus([]);
        $statusRequest->setModel(new ArrayObject([
            'quickpayPaymentId' => $quickpayPayment->getId()
        ]));

        $action = new StatusAction();
        $action->setApi($this->api);
        $action->execute($statusRequest);
        $this->assertTrue($statusRequest->isNew(), 'Request should be marked as new');
    }

    /**
     * @test
     * @throws \Exception
     */
    public function shouldMarkNewAsAuthorized(): void
    {
        $params = new ArrayObject([
            'payment' => $this->createPayment(),
        ]);
        $quickpayPayment = $this->api->getPayment($params);

        $this->api->authorizePayment($quickpayPayment, new ArrayObject([
            'card' => $this->getTestCard()->toArray(),
            'acquirer' => 'clearhaus',
            'amount' => 1,
        ]));

        $statusRequest = new GetHumanStatus([]);
        $statusRequest->setModel(new ArrayObject([
            'quickpayPaymentId' => $quickpayPayment->getId()
        ]));

        $action = new StatusAction();
        $action->setApi($this->api);
        $action->execute($statusRequest);
        $this->assertTrue($statusRequest->isAuthorized(), 'Request should be marked as authorized');
    }

    /**
     * @test
     * @throws \Exception
     */
    public function shouldMarkNewAsFailed(): void
    {
        $params = new ArrayObject([
            'payment' => $this->createPayment(),
        ]);
        $quickpayPayment = $this->api->getPayment($params);

        $this->api->authorizePayment($quickpayPayment, new ArrayObject([
            'card' => $this->getCaptureRejectedTestCard()->toArray(),
            'acquirer' => 'clearhaus',
            'amount' => $params['payment']->getTotalAmount(),
        ]));

        $this->api->capturePayment($quickpayPayment, new ArrayObject([
            'amount' => $params['payment']->getTotalAmount(),
        ]));

        $statusRequest = new GetHumanStatus([]);
        $statusRequest->setModel(new ArrayObject([
            'quickpayPaymentId' => $quickpayPayment->getId()
        ]));

        $action = new StatusAction();
        $action->setApi($this->api);
        $action->execute($statusRequest);
        $this->assertTrue($statusRequest->isFailed(), 'Request should be marked as failed');
    }

    /**
     * @test
     * @throws \Exception
     */
    public function shouldMarkPendingAsPending(): void
    {
        $params = new ArrayObject([
            'payment' => $this->createPayment(),
        ]);
        $quickpayPayment = $this->api->getPayment($params);

        $quickpayPayment = $this->api->authorizePayment($quickpayPayment, new ArrayObject([
            'card' => $this->getTestCard()->toArray(),
            'acquirer' => 'clearhaus',
            'amount' => 1,
        ]));
        $this->assertEquals(QuickPayPayment::STATE_PENDING, $quickpayPayment->getState());

        $statusRequest = new GetHumanStatus([]);
        $statusRequest->setModel(new ArrayObject([
            'quickpayPayment' => $quickpayPayment,
        ]));

        $action = new StatusAction();
        $action->setApi($this->api);
        $action->execute($statusRequest);
        $this->assertTrue($statusRequest->isPending(), 'Request should be marked as pending');
    }

    /**
     * @test
     * @throws \Exception
     */
    public function shouldMarkRejectedAsFailed(): void
    {
        $params = new ArrayObject([
            'payment' => $this->createPayment(),
        ]);
        $quickpayPayment = $this->api->getPayment($params);

        $this->api->authorizePayment($quickpayPayment, new ArrayObject([
            'card' => $this->getAuthorizeRejectedTestCard()->toArray(),
            'acquirer' => 'clearhaus',
            'amount' => 1,
        ]));

        $quickpayPayment = $this->api->getPayment(new ArrayObject([
            'quickpayPaymentId' => $quickpayPayment->getId()
        ]));

        $this->assertEquals(QuickpayPayment::STATE_REJECTED, $quickpayPayment->getState());
        $this->assertEquals(QuickPayPaymentOperation::TYPE_AUTHORIZE, $quickpayPayment->getLatestOperation()->getType());

        $statusRequest = new GetHumanStatus([]);
        $statusRequest->setModel(new ArrayObject([
            'quickpayPayment' => $quickpayPayment
        ]));

        $action = new StatusAction();
        $action->setApi($this->api);
        $action->execute($statusRequest);
        $this->assertTrue($statusRequest->isFailed(), 'Request should be marked as failed');
    }

    /**
     * @test
     * @throws \Exception
     */
    public function shouldMarkProcessedAsCaptured(): void
    {
        $params = new ArrayObject([
            'payment' => $this->createPayment(),
        ]);
        $quickpayPayment = $this->api->getPayment($params);

        $this->api->authorizePayment($quickpayPayment, new ArrayObject([
            'card' => $this->getTestCard()->toArray(),
            'acquirer' => 'clearhaus',
            'amount' => $params['payment']->getTotalAmount(),
        ]));

        $this->api->capturePayment($quickpayPayment, new ArrayObject([
            'amount' => $params['payment']->getTotalAmount(),
        ]));

        $statusRequest = new GetHumanStatus([]);
        $statusRequest->setModel(new ArrayObject([
            'quickpayPaymentId' => $quickpayPayment->getId()
        ]));

        $action = new StatusAction();
        $action->setApi($this->api);
        $action->execute($statusRequest);
        $this->assertTrue($statusRequest->isCaptured(), 'Request should be marked as captured');
    }

    /**
     * @test
     * @throws \Exception
     */
    public function shouldMarkProcessedAsAuthorized(): void
    {
        $params = new ArrayObject([
            'payment' => $this->createPayment(),
        ]);
        $quickpayPayment = $this->api->getPayment($params);

        $this->api->authorizePayment($quickpayPayment, new ArrayObject([
            'card' => $this->getTestCard()->toArray(),
            'acquirer' => 'clearhaus',
            'amount' => $params['payment']->getTotalAmount(),
        ]));

        sleep(1);
        $this->api->cancelPayment($quickpayPayment, new ArrayObject([
            'amount' => $params['payment']->getTotalAmount(),
        ]));

        $quickpayPayment = $this->api->getPayment(new ArrayObject([
            'quickpayPaymentId' => $quickpayPayment->getId()
        ]));

        $this->assertEquals(QuickpayPayment::STATE_PROCESSED, $quickpayPayment->getState());
        $this->assertEquals(QuickPayPaymentOperation::TYPE_CANCEL, $quickpayPayment->getLatestOperation()->getType());

        $statusRequest = new GetHumanStatus([]);
        $statusRequest->setModel(new ArrayObject([
            'quickpayPayment' => $quickpayPayment,
        ]));

        $action = new StatusAction();
        $action->setApi($this->api);
        $action->execute($statusRequest);
        $this->assertTrue($statusRequest->isCanceled(), 'Request should be marked as canceled');
    }
}
