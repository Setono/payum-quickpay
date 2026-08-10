<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Tests\Action;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\LogicException;
use Payum\Core\Request\Capture;
use Payum\Core\Security\GenericTokenFactoryAwareInterface;
use ReflectionClass;
use ReflectionException;
use Setono\Payum\Quickpay\Action\CaptureAction;
use Setono\Quickpay\Enum\OperationType;
use Setono\Quickpay\Enum\PaymentState;
use Setono\Quickpay\Exception\ValidationException;

class CaptureActionTest extends ActionTestAbstract
{
    protected $requestClass = Capture::class;

    protected $actionClass = CaptureAction::class;

    /**
     * A capture needs no token: only `AuthorizeAction` mints one, for the callback url. Implementing
     * the aware interface here would drag in `GenericTokenFactoryInterface`, which payum/core has
     * deprecated, for nothing — see #3.
     *
     * @test
     *
     * @throws ReflectionException
     */
    public function shouldNotDependOnTheTokenFactory(): void
    {
        $rc = new ReflectionClass($this->actionClass);

        self::assertFalse($rc->implementsInterface(GenericTokenFactoryAwareInterface::class));
    }

    /**
     * @test
     */
    public function shouldCapturePayment(): void
    {
        $details = new ArrayObject(['quickpayPaymentId' => 1001, 'amount' => 100]);

        /** @var Capture $capture */
        $capture = new $this->requestClass($details);

        $action = new CaptureAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        $this->queuePayment([
            'state' => PaymentState::Processed->value,
            'operations' => [$this->operation(OperationType::Capture)],
        ]);

        $action->execute($capture);

        $requests = $this->getRequests();
        self::assertCount(1, $requests);
        $this->assertRequest($requests[0], 'POST', '#/payments/1001/capture$#');
        self::assertSame('', $requests[0]->getUri()->getQuery(), 'Operations are asynchronous unless the gateway is configured otherwise');
        self::assertSame(100, $this->decodeBody($requests[0])['amount']);
    }

    /**
     * @test
     */
    public function shouldCaptureThePartialAmountWhenTheDetailsCarryAnOverride(): void
    {
        $details = new ArrayObject([
            'quickpayPaymentId' => 1001,
            'amount' => 1000,
            'capture_amount' => 250,
        ]);

        /** @var Capture $capture */
        $capture = new $this->requestClass($details);

        $action = new CaptureAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        $this->queuePayment([
            'state' => PaymentState::Processed->value,
            'balance' => 250,
            'operations' => [$this->operation(OperationType::Capture, amount: 250)],
        ]);

        $action->execute($capture);

        $requests = $this->getRequests();
        self::assertCount(1, $requests);
        self::assertSame(250, $this->decodeBody($requests[0])['amount']);

        self::assertFalse(
            $details->offsetExists('capture_amount'),
            'The override must be consumed, or the next capture would silently be partial too',
        );
        self::assertSame(1000, $details['amount'], 'The full amount must be left alone');
    }

    /**
     * @test
     */
    public function shouldCaptureSynchronouslyWhenTheApiIsSynchronized(): void
    {
        $details = new ArrayObject(['quickpayPaymentId' => 1001, 'amount' => 100]);

        /** @var Capture $capture */
        $capture = new $this->requestClass($details);

        $action = new CaptureAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->createApi(synchronized: true));

        $this->queuePayment([
            'state' => PaymentState::Processed->value,
            'operations' => [$this->operation(OperationType::Capture)],
        ]);

        $action->execute($capture);

        $requests = $this->getRequests();
        self::assertCount(1, $requests);
        $this->assertRequest($requests[0], 'POST', '#/payments/1001/capture$#');
        self::assertSame('synchronized', $requests[0]->getUri()->getQuery());
    }

    /**
     * A model without a quickpayPaymentId has no payment to capture. The guard throws before any
     * HTTP happens — without it, `(int) null = 0` would reach the API as `POST /payments/0/capture`.
     *
     * @test
     */
    public function shouldThrowWhenThePaymentHasNotBeenCreated(): void
    {
        /** @var Capture $capture */
        $capture = new $this->requestClass(new ArrayObject(['amount' => 100]));

        $action = new CaptureAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('quickpayPaymentId');

        try {
            $action->execute($capture);
        } finally {
            self::assertCount(0, $this->getRequests(), 'No API call may be made for a payment that does not exist yet');
        }
    }

    /**
     * @test
     */
    public function shouldThrowWhenCapturingNonAuthorizedPayment(): void
    {
        $details = new ArrayObject(['quickpayPaymentId' => 1001, 'amount' => 100]);

        /** @var Capture $capture */
        $capture = new $this->requestClass($details);

        $action = new CaptureAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        // Capturing a payment that has not been authorized fails with a validation error (400).
        $this->queueResponse('{"message":"Validation error in capture"}', 400);

        $this->expectException(ValidationException::class);
        $action->execute($capture);
    }
}
