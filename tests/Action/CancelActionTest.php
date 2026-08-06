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
     * Cancelling an already captured or cancelled payment is a real state conflict, not something to
     * hide: swallowing it would tell a shop it had cancelled a payment whose money is still held. The
     * first message is what a live account returns for that case (2026-08).
     *
     * @test
     *
     * @dataProvider errorMessageProvider
     */
    public function shouldLetErrorsSurface(string $body): void
    {
        $details = new ArrayObject(['quickpayPaymentId' => 1001, 'amount' => 100]);

        /** @var Cancel $cancel */
        $cancel = new $this->requestClass($details);

        $action = new CancelAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        $this->queueResponse($body, 400);

        $this->expectException(ValidationException::class);

        try {
            $action->execute($cancel);
        } finally {
            self::assertCount(1, $this->getRequests());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function errorMessageProvider(): iterable
    {
        yield 'invalid state, live wording' => ['{"message":"Validation error: Payment is not in a valid state for cancel","errors":{},"error_code":null}'];
        yield 'older invalid-state wording' => ['{"message":"Transaction in wrong state for this operation"}'];
        yield 'any other error' => ['{"message":"Some other error"}'];
        yield 'no message at all' => ['{}'];
    }
}
