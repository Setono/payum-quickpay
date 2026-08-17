<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Tests\Action;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\LogicException;
use Payum\Core\Model\Token;
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Request\Authorize;
use Payum\Core\Security\GenericTokenFactoryAwareInterface;
use ReflectionClass;
use ReflectionException;
use Setono\Payum\Quickpay\Action\AuthorizeAction;
use Setono\Quickpay\Enum\OperationType;
use Setono\Quickpay\Enum\PaymentState;

class AuthorizeActionTest extends ActionTestAbstract
{
    protected $requestClass = Authorize::class;

    protected $actionClass = AuthorizeAction::class;

    /**
     * Only the internal CreatePaymentLinkAction mints a token; the public actions delegate to it
     * rather than dragging payum/core's deprecated GenericTokenFactoryInterface in themselves (#3).
     *
     * @test
     *
     * @throws ReflectionException
     */
    public function shouldNotDependOnTheTokenFactory(): void
    {
        self::assertFalse((new ReflectionClass($this->actionClass))->implementsInterface(GenericTokenFactoryAwareInterface::class));
    }

    /**
     * A fresh payment: create the link (auth-only, since this is Authorize) and redirect. The token
     * travels along so the notify token can be minted from it.
     *
     * @test
     */
    public function shouldSendAFreshPaymentToThePaymentWindow(): void
    {
        $token = new Token();
        $token->setGatewayName('quickpay');

        $details = new ArrayObject([
            'quickpayPaymentId' => 1001,
            'amount' => 100,
            'continue_url' => 'theContinueUrl',
            'cancel_url' => 'theCancelUrl',
        ]);
        $token->setDetails($details);

        /** @var Authorize $authorize */
        $authorize = new $this->requestClass($token);
        $authorize->setModel($details);

        // The status fetch, then the link.
        $this->queuePayment(['state' => PaymentState::Initial->value, 'operations' => []]);
        $this->queueResponse('{"url":"https://payment.quickpay.net/payments/1001/payment-window"}');

        try {
            $this->action()->execute($authorize);
            self::fail('An HttpRedirect reply should have been thrown');
        } catch (HttpRedirect $redirect) {
            self::assertSame('https://payment.quickpay.net/payments/1001/payment-window', $redirect->getUrl());
        }

        $requests = $this->getRequests();
        self::assertCount(2, $requests);
        $this->assertRequest($requests[0], 'GET', '#/payments/1001$#');
        $this->assertRequest($requests[1], 'PUT', '#/payments/1001/link$#');

        $body = $this->decodeBody($requests[1]);
        self::assertSame(100, $body['amount']);
        self::assertSame('theContinueUrl', $body['continue_url']);
        self::assertSame('theCancelUrl', $body['cancel_url']);
        self::assertSame('https://shop.example/notify?payum_token=stub-notify', $body['callback_url']);
        // The api under test has auto_capture on, so this Authorize is a sale — the deprecated
        // option's meaning. The other value is pinned in shouldCreateAnAuthOnlyLinkWhenAutoCaptureIsOff.
        self::assertTrue($body['auto_capture']);

        self::assertSame('https://shop.example/notify?payum_token=stub-notify', $details['callback_url']);
        self::assertCount(1, $this->tokenFactory->notifyTokensCreated);
    }

    /**
     * @test
     */
    public function shouldCreateAnAuthOnlyLinkWhenAutoCaptureIsOff(): void
    {
        $this->queuePayment(['state' => PaymentState::Initial->value, 'operations' => []]);
        $this->queueResponse('{"url":"https://payment.quickpay.net/payments/1001/payment-window"}');

        try {
            $this->action(autoCapture: false)->execute($this->authorize());
        } catch (HttpRedirect) {
        }

        self::assertFalse($this->decodeBody($this->getRequests()[1])['auto_capture']);
    }

    /**
     * The return trip: Quickpay sends the customer back to the token url, Payum re-executes the
     * Authorize that sent them out, and the payment is now authorized. Redirecting again would loop;
     * this must be a no-op that touches nothing but the balance.
     *
     * @test
     */
    public function shouldDoNothingWhenThePaymentIsAlreadyAuthorized(): void
    {
        $details = $this->details();

        $this->queuePayment([
            'state' => PaymentState::New->value,
            'balance' => 0,
            'operations' => [$this->operation(OperationType::Authorize, amount: 100)],
        ]);

        $this->action()->execute($this->authorize($details));

        $requests = $this->getRequests();
        self::assertCount(1, $requests, 'Only the status fetch — no link may be created again');
        $this->assertRequest($requests[0], 'GET', '#/payments/1001$#');
        self::assertSame(0, $details['balance']);
        self::assertSame([], $this->tokenFactory->notifyTokensCreated);
    }

    /**
     * Past authorization altogether — captured (approved or still queued). Authorize has nothing to add.
     *
     * @test
     *
     * @dataProvider capturedProvider
     *
     * @param array<string, mixed> $capture
     */
    public function shouldDoNothingWhenThePaymentIsAlreadyCaptured(array $capture): void
    {
        $this->queuePayment([
            'state' => PaymentState::Processed->value,
            'operations' => [$this->operation(OperationType::Authorize, amount: 100), $capture],
        ]);

        $this->action()->execute($this->authorize());

        self::assertCount(1, $this->getRequests());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function capturedProvider(): iterable
    {
        yield 'approved capture' => [['id' => 2, 'type' => 'capture', 'amount' => 100, 'pending' => false, 'qp_status_code' => '20000']];
        yield 'pending capture' => [['id' => 2, 'type' => 'capture', 'amount' => 100, 'pending' => true, 'qp_status_code' => null]];
    }

    /**
     * An authorize in flight (3-D Secure, an asynchronous acquirer): creating another link would race
     * its outcome. Wait for it.
     *
     * @test
     */
    public function shouldDoNothingWhileAnAuthorizeIsPending(): void
    {
        $this->queuePayment([
            'state' => PaymentState::Pending->value,
            'operations' => [$this->operation(OperationType::Authorize, null, amount: 100, pending: true)],
        ]);

        $this->action()->execute($this->authorize());

        self::assertCount(1, $this->getRequests());
    }

    /**
     * A declined attempt is not the end: Quickpay lets the customer try again on the same payment,
     * so a rejected authorize — and nothing approved or pending — means "send them to the window".
     *
     * @test
     */
    public function shouldSendTheCustomerBackToTheWindowAfterADeclinedAttempt(): void
    {
        $this->queuePayment([
            'state' => PaymentState::Rejected->value,
            'operations' => [$this->operation(OperationType::Authorize, '40000', amount: 100)],
        ]);
        $this->queueResponse('{"url":"https://payment.quickpay.net/payments/1001/payment-window"}');

        try {
            $this->action()->execute($this->authorize());
            self::fail('An HttpRedirect reply should have been thrown');
        } catch (HttpRedirect $redirect) {
            self::assertSame('https://payment.quickpay.net/payments/1001/payment-window', $redirect->getUrl());
        }

        self::assertCount(2, $this->getRequests());
    }

    /**
     * @test
     */
    public function shouldThrowWhenThePaymentHasNotBeenCreated(): void
    {
        $details = $this->details();
        unset($details['quickpayPaymentId']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('quickpayPaymentId');

        try {
            $this->action()->execute($this->authorize($details));
        } finally {
            self::assertCount(0, $this->getRequests(), 'No API call may be made for a payment that does not exist yet');
        }
    }

    private function action(bool $autoCapture = true): AuthorizeAction
    {
        $action = new AuthorizeAction();
        $action->setGateway($this->gateway);
        $action->setApi($autoCapture ? $this->api : $this->createApi(autoCapture: false));

        return $action;
    }

    /**
     * @param ArrayObject<string, mixed>|null $details
     */
    private function authorize(?ArrayObject $details = null): Authorize
    {
        /** @var Authorize $authorize */
        $authorize = new $this->requestClass($details ?? $this->details());

        return $authorize;
    }

    /**
     * Details with a preset callback_url, so no token is needed.
     *
     * @return ArrayObject<string, mixed>
     */
    private function details(): ArrayObject
    {
        return new ArrayObject([
            'quickpayPaymentId' => 1001,
            'amount' => 100,
            'continue_url' => 'theContinueUrl',
            'cancel_url' => 'theCancelUrl',
            'callback_url' => 'thePresetCallbackUrl',
        ]);
    }
}
