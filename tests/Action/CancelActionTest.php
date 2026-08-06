<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Tests\Action;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Request\Cancel;
use Setono\Payum\Quickpay\Action\CancelAction;
use Setono\Quickpay\Enum\OperationType;
use Setono\Quickpay\Enum\PaymentState;
use Setono\Quickpay\Exception\ValidationException;

class CancelActionTest extends ActionTestAbstract
{
    protected $requestClass = Cancel::class;

    protected $actionClass = CancelAction::class;

    /**
     * @test
     */
    public function shouldCancelPayment(): void
    {
        $details = new ArrayObject(['quickpayPaymentId' => 1001, 'amount' => 100]);

        /** @var Cancel $cancel */
        $cancel = new $this->requestClass($details);

        $action = new CancelAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        $this->queuePayment([
            'state' => PaymentState::Processed->value,
            'operations' => [$this->operation(OperationType::Cancel)],
        ]);

        $action->execute($cancel);

        $requests = $this->getRequests();
        self::assertCount(1, $requests);
        $this->assertRequest($requests[0], 'POST', '#/payments/1001/cancel$#');
        // Cancel takes no body.
        self::assertSame('', (string) $requests[0]->getBody());
    }

    /**
     * Quickpay reports an already finalized payment as an invalid-state error; the action treats it as
     * a no-op so cancelling is idempotent.
     *
     * Both wordings are covered because the API's phrasing has changed: the second one is what a live
     * account actually returned when cancelling a captured payment (2026-08), and the first is what
     * this test used to assert on its own — an invented fixture that let the action's message match go
     * stale unnoticed until the e2e harness cancelled a real captured payment.
     *
     * @test
     *
     * @dataProvider invalidStateMessageProvider
     */
    public function shouldSwallowInvalidStateError(string $message): void
    {
        $details = new ArrayObject(['quickpayPaymentId' => 1001, 'amount' => 100]);

        /** @var Cancel $cancel */
        $cancel = new $this->requestClass($details);

        $action = new CancelAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        $this->queueResponse(json_encode(['message' => $message, 'errors' => [], 'error_code' => null], \JSON_THROW_ON_ERROR), 400);

        $action->execute($cancel);

        self::assertCount(1, $this->getRequests());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidStateMessageProvider(): iterable
    {
        yield 'older wording' => ['Transaction in wrong state for this operation'];
        yield 'live wording, 2026-08' => ['Validation error: Payment is not in a valid state for cancel'];
        // The match is deliberately case-insensitive: if the wording can drift, so can the casing.
        yield 'different casing' => ['Transaction in WRONG STATE for this operation'];
    }

    /**
     * A validation error carrying no message at all must not be mistaken for the invalid-state case.
     *
     * @test
     */
    public function shouldRethrowValidationErrorWithoutAMessage(): void
    {
        $details = new ArrayObject(['quickpayPaymentId' => 1001, 'amount' => 100]);

        /** @var Cancel $cancel */
        $cancel = new $this->requestClass($details);

        $action = new CancelAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        $this->queueResponse('{}', 400);

        $this->expectException(ValidationException::class);
        $action->execute($cancel);
    }

    /**
     * @test
     */
    public function shouldRethrowOtherErrors(): void
    {
        $details = new ArrayObject(['quickpayPaymentId' => 1001, 'amount' => 100]);

        /** @var Cancel $cancel */
        $cancel = new $this->requestClass($details);

        $action = new CancelAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        $this->queueResponse('{"message":"Some other error"}', 400);

        $this->expectException(ValidationException::class);
        $action->execute($cancel);
    }
}
