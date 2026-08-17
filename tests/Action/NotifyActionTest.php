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
     * The callback for a DECLINED payment must complete without error: the api under test has
     * auto_capture on, and a rejected authorize has type `authorize` — but there is nothing to
     * capture. Throwing here would 500 the notify endpoint and have Quickpay retry a callback that
     * can never succeed.
     *
     * @test
     */
    public function shouldCompleteQuietlyWhenTheAuthorizeWasDeclined(): void
    {
        $body = '{"id":1001}';
        $this->httpRequestAction->setHttpRequest($body, [
            CallbackValidator::CHECKSUM_HEADER => hash_hmac('sha256', $body, 'test-privatekey'),
        ]);

        $this->queuePayment([
            'state' => PaymentState::Rejected->value,
            'operations' => [$this->operation(OperationType::Authorize, '40000', amount: 100)],
        ]);

        $action = new NotifyAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        $action->execute($this->notify());

        // Only the reload — the declined authorize is not captured.
        $requests = $this->getRequests();
        self::assertCount(1, $requests);
        $this->assertRequest($requests[0], 'GET', '#/payments/1001$#');
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

    /**
     * Symfony's HeaderBag lower-cases header names, and the Symfony bridge is what feeds
     * GetHttpRequest in production Sylius/Symfony setups — so the lower-cased spelling is the shape
     * the checksum lookup actually meets there. It must match case-insensitively.
     *
     * @test
     */
    public function shouldAcceptALowerCasedChecksumHeader(): void
    {
        $body = '{"id":1001}';
        $this->httpRequestAction->setHttpRequest($body, [
            strtolower(CallbackValidator::CHECKSUM_HEADER) => hash_hmac('sha256', $body, 'test-privatekey'),
        ]);

        // No operations: ConfirmPayment fetches and finds nothing to confirm.
        $this->queuePayment(['state' => PaymentState::Initial->value, 'operations' => []]);

        $action = new NotifyAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        $action->execute($this->notify());

        $requests = $this->getRequests();
        self::assertCount(1, $requests);
        $this->assertRequest($requests[0], 'GET', '#/payments/1001$#');
    }

    /**
     * Bridges may expose a header's value as a list. The first entry is the checksum.
     *
     * @test
     */
    public function shouldAcceptAListValuedChecksumHeader(): void
    {
        $body = '{"id":1001}';
        $this->httpRequestAction->setHttpRequest($body, [
            CallbackValidator::CHECKSUM_HEADER => [hash_hmac('sha256', $body, 'test-privatekey')],
        ]);

        $this->queuePayment(['state' => PaymentState::Initial->value, 'operations' => []]);

        $action = new NotifyAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        $action->execute($this->notify());

        self::assertCount(1, $this->getRequests());
    }

    private function notify(): Notify
    {
        return new Notify(new ArrayObject(['quickpayPaymentId' => 1001, 'amount' => 100]));
    }
}
