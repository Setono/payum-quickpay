<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Tests\Action;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Reply\HttpResponse;
use Payum\Core\Request\Notify;
use Setono\Payum\Quickpay\Action\NotifyAction;
use Setono\Quickpay\Callback\CallbackValidator;
use Setono\Quickpay\Enum\OperationType;
use Setono\Quickpay\Enum\PaymentState;

class NotifyActionTest extends ActionTestAbstract
{
    protected $requestClass = Notify::class;

    protected $actionClass = NotifyAction::class;

    /**
     * @test
     */
    public function shouldConfirmPaymentWhenChecksumIsValid(): void
    {
        $body = '{"id":1001}';
        $this->httpRequestAction->setHttpRequest($body, [
            CallbackValidator::CHECKSUM_HEADER => hash_hmac('sha256', $body, 'test-privatekey'),
        ]);

        // ConfirmPayment reloads the payment and, with auto_capture on + matching amount, captures.
        $this->queuePayment([
            'state' => PaymentState::New->value,
            'operations' => [$this->operation(OperationType::Authorize, amount: 100)],
        ]);
        $this->queuePayment([
            'state' => PaymentState::Processed->value,
            'operations' => [$this->operation(OperationType::Capture)],
        ]);

        $action = new NotifyAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        $action->execute($this->notify());

        $requests = $this->getRequests();
        self::assertCount(2, $requests);
        $this->assertRequest($requests[0], 'GET', '#/payments/1001$#');
        $this->assertRequest($requests[1], 'POST', '#/payments/1001/capture$#');
    }

    /**
     * @test
     */
    public function shouldRejectInvalidChecksum(): void
    {
        $this->httpRequestAction->setHttpRequest('{"id":1001}', [
            CallbackValidator::CHECKSUM_HEADER => 'an-invalid-checksum',
        ]);

        $action = new NotifyAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        try {
            $action->execute($this->notify());
            self::fail('An HttpResponse reply should have been thrown');
        } catch (HttpResponse $reply) {
            self::assertSame(400, $reply->getStatusCode());
        }

        self::assertCount(0, $this->getRequests(), 'No API call should be made for an invalid callback');
    }

    /**
     * @test
     */
    public function shouldRejectMissingChecksum(): void
    {
        $this->httpRequestAction->setHttpRequest('{"id":1001}', []);

        $action = new NotifyAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        try {
            $action->execute($this->notify());
            self::fail('An HttpResponse reply should have been thrown');
        } catch (HttpResponse $reply) {
            self::assertSame(400, $reply->getStatusCode());
        }

        self::assertCount(0, $this->getRequests(), 'No API call should be made for an unsigned callback');
    }

    private function notify(): Notify
    {
        return new Notify(new ArrayObject(['quickpayPaymentId' => 1001, 'amount' => 100]));
    }
}
