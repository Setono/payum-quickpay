<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Tests\Action;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Request\Sync;
use PHPUnit\Framework\Attributes\Test;
use Setono\Payum\Quickpay\Action\SyncAction;
use Setono\Quickpay\Enum\OperationType;
use Setono\Quickpay\Enum\PaymentState;

class SyncActionTest extends ActionTestAbstract
{
    protected static string $requestClass = Sync::class;

    protected static string $actionClass = SyncAction::class;

    #[Test]
    public function shouldWriteTheScalarSnapshotIntoTheDetails(): void
    {
        $details = new ArrayObject(['quickpayPaymentId' => 1001, 'amount' => 1000]);

        /** @var Sync $sync */
        $sync = new static::$requestClass($details);

        $action = new SyncAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        $this->queuePayment([
            'state' => PaymentState::Processed->value,
            'balance' => 750,
            'operations' => [$this->operation(OperationType::Capture, amount: 1000)],
        ]);

        $action->execute($sync);

        $requests = $this->getRequests();
        self::assertCount(1, $requests);
        $this->assertRequest($requests[0], 'GET', '#/payments/1001$#');

        self::assertSame(750, $details['balance']);
        self::assertSame(PaymentState::Processed->value, $details['state']);

        // Only scalars may be persisted, per the 2.0 details contract.
        foreach ($details as $value) {
            self::assertIsNotObject($value, 'Details must not contain objects');
        }
    }

    /**
     * A model that has not been converted yet has nothing to sync. That is a normal state, not an
     * error, so it must not throw and must not call the API.
     */
    #[Test]
    public function shouldDoNothingWhenThePaymentDoesNotExistYet(): void
    {
        $details = new ArrayObject(['amount' => 1000]);

        /** @var Sync $sync */
        $sync = new static::$requestClass($details);

        $action = new SyncAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        $action->execute($sync);

        self::assertCount(0, $this->getRequests());
        self::assertFalse($details->offsetExists('balance'));
    }
}
