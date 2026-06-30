<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Tests\Action;

use Payum\Core\Bridge\Spl\ArrayObject;
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
     * @test
     *
     * @throws ReflectionException
     */
    public function shouldImplementGenericTokenFactoryAwareInterface(): void
    {
        $rc = new ReflectionClass($this->actionClass);

        self::assertTrue($rc->implementsInterface(GenericTokenFactoryAwareInterface::class));
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
        self::assertSame(100, $this->decodeBody($requests[0])['amount']);
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
