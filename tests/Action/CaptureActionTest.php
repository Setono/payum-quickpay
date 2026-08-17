<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Tests\Action;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\LogicException;
use Payum\Core\Model\Token;
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Request\Capture;
use Payum\Core\Security\GenericTokenFactoryAwareInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionException;
use Setono\Payum\Quickpay\Action\CaptureAction;
use Setono\Quickpay\Enum\OperationType;
use Setono\Quickpay\Enum\PaymentState;
use Setono\Quickpay\Exception\ValidationException;

class CaptureActionTest extends ActionTestAbstract
{
    protected static string $requestClass = Capture::class;

    protected static string $actionClass = CaptureAction::class;

    /**
     * Only the internal CreatePaymentLinkAction mints a token; the public actions delegate to it
     * rather than dragging payum/core's deprecated GenericTokenFactoryInterface in themselves (#3).
     *
     * @throws ReflectionException
     */
    #[Test]
    public function shouldNotDependOnTheTokenFactory(): void
    {
        self::assertFalse((new ReflectionClass(static::$actionClass))->implementsInterface(GenericTokenFactoryAwareInterface::class));
    }

    // -- The interactive entry point: a payment nobody has paid yet -----------------------------

    /**
     * Payum's convention, and what Payum's capture controller and Sylius's default checkout do:
     * executing Capture against a fresh payment drives the whole flow. The link is created WITH
     * auto_capture — "capture" for a payment nobody has paid yet means "have Quickpay take the money
     * the moment the card is authorized" — and the customer is redirected to the window.
     */
    #[Test]
    public function shouldSendAFreshPaymentToThePaymentWindowWithAutoCapture(): void
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

        /** @var Capture $capture */
        $capture = new static::$requestClass($token);
        $capture->setModel($details);

        $this->queuePayment(['state' => PaymentState::Initial->value, 'operations' => []]);
        $this->queueResponse('{"url":"https://payment.quickpay.net/payments/1001/payment-window"}');

        try {
            $this->action()->execute($capture);
            self::fail('An HttpRedirect reply should have been thrown');
        } catch (HttpRedirect $redirect) {
            self::assertSame('https://payment.quickpay.net/payments/1001/payment-window', $redirect->getUrl());
        }

        $requests = $this->getRequests();
        self::assertCount(2, $requests);
        $this->assertRequest($requests[0], 'GET', '#/payments/1001$#');
        $this->assertRequest($requests[1], 'PUT', '#/payments/1001/link$#');

        $body = $this->decodeBody($requests[1]);
        self::assertTrue($body['auto_capture'], 'Capture means capture: the window must capture at authorization');
        self::assertSame(100, $body['amount']);
        self::assertSame('https://shop.example/notify?payum_token=stub-notify', $body['callback_url']);
        self::assertCount(1, $this->tokenFactory->notifyTokensCreated, 'The notify token is minted from the capture token');
    }

    /**
     * Whatever the gateway's (deprecated) auto_capture option says, Capture captures. The option
     * only ever meant something for Authorize.
     */
    #[Test]
    public function shouldCaptureAtAuthorizationRegardlessOfTheAutoCaptureOption(): void
    {
        $this->queuePayment(['state' => PaymentState::Initial->value, 'operations' => []]);
        $this->queueResponse('{"url":"https://payment.quickpay.net/payments/1001/payment-window"}');

        try {
            $this->action(autoCapture: false)->execute($this->capture());
        } catch (HttpRedirect) {
        }

        self::assertTrue($this->decodeBody($this->getRequests()[1])['auto_capture']);
    }

    #[Test]
    public function shouldSendTheCustomerBackToTheWindowAfterADeclinedAttempt(): void
    {
        $this->queuePayment([
            'state' => PaymentState::Rejected->value,
            'operations' => [$this->operation(OperationType::Authorize, '40000', amount: 100)],
        ]);
        $this->queueResponse('{"url":"https://payment.quickpay.net/payments/1001/payment-window"}');

        try {
            $this->action()->execute($this->capture());
            self::fail('An HttpRedirect reply should have been thrown');
        } catch (HttpRedirect) {
        }

        self::assertCount(2, $this->getRequests());
    }

    #[Test]
    public function shouldDoNothingWhileAnAuthorizeIsPending(): void
    {
        $this->queuePayment([
            'state' => PaymentState::Pending->value,
            'operations' => [$this->operation(OperationType::Authorize, null, amount: 100, pending: true)],
        ]);

        $this->action()->execute($this->capture());

        self::assertCount(1, $this->getRequests(), 'Neither a link nor a capture while the authorize is in flight');
    }

    // -- The return trip of that flow: authorized through a link that captures by itself ---------

    /**
     * The customer is back, Payum re-executes the Capture that sent them out, and the payment is
     * authorized through a link with auto_capture. Quickpay is capturing (or has); a capture from
     * here could only ever double up on it. This is the one rule that must never break.
     *
     * @param list<array<string, mixed>> $operations
     */
    #[Test]
    #[DataProvider('linkAutoCapturesProvider')]
    public function shouldNeverCaptureWhenTheLinkCapturesByItself(string $state, array $operations, ?int $balance): void
    {
        $details = $this->details();

        $this->queuePayment([
            'state' => $state,
            'balance' => $balance,
            'operations' => $operations,
            'link' => $this->link(autoCapture: true),
        ]);

        $this->action()->execute($this->capture($details));

        $requests = $this->getRequests();
        self::assertCount(1, $requests, 'Only the fetch — no capture may be issued');
        $this->assertRequest($requests[0], 'GET', '#/payments/1001$#');
        self::assertSame($balance, $details['balance']);
    }

    /**
     * @return iterable<string, array{string, list<array<string, mixed>>, int|null}>
     */
    public static function linkAutoCapturesProvider(): iterable
    {
        $authorize = ['id' => 1, 'type' => 'authorize', 'amount' => 100, 'pending' => false, 'qp_status_code' => '20000'];

        yield 'authorized, Quickpay has not recorded its capture yet' => [
            PaymentState::New->value, [$authorize], 0,
        ];
        yield 'authorized, Quickpay capture queued' => [
            PaymentState::Pending->value,
            [$authorize, ['id' => 2, 'type' => 'capture', 'amount' => 100, 'pending' => true, 'qp_status_code' => null]],
            0,
        ];
        yield 'captured' => [
            PaymentState::Processed->value,
            [$authorize, ['id' => 2, 'type' => 'capture', 'amount' => 100, 'pending' => false, 'qp_status_code' => '20000']],
            100,
        ];
    }

    // -- Settling an Authorize flow later: capture through the API ------------------------------

    #[Test]
    public function shouldCapturePayment(): void
    {
        $details = $this->details();

        $this->queueAuthorized();
        $this->queuePayment([
            'state' => PaymentState::Processed->value,
            'operations' => [$this->operation(OperationType::Capture)],
        ]);

        $this->action()->execute($this->capture($details));

        $requests = $this->getRequests();
        self::assertCount(2, $requests);
        $this->assertRequest($requests[0], 'GET', '#/payments/1001$#');
        $this->assertRequest($requests[1], 'POST', '#/payments/1001/capture$#');
        self::assertSame('', $requests[1]->getUri()->getQuery(), 'Operations are asynchronous unless the gateway is configured otherwise');
        self::assertSame(100, $this->decodeBody($requests[1])['amount']);
    }

    /**
     * A link created WITHOUT auto_capture (an Authorize flow, or a payment where the flag is
     * absent altogether) means the shop settles itself — so it captures.
     */
    #[Test]
    #[DataProvider('plainLinkProvider')]
    public function shouldCaptureWhenTheLinkDoesNotCaptureByItself(mixed $link): void
    {
        $overrides = [
            'state' => PaymentState::New->value,
            'operations' => [$this->operation(OperationType::Authorize, amount: 100)],
        ];
        if (null !== $link) {
            $overrides['link'] = $link;
        }
        $this->queuePayment($overrides);
        $this->queuePayment(['state' => PaymentState::Processed->value, 'operations' => [$this->operation(OperationType::Capture)]]);

        $this->action()->execute($this->capture());

        self::assertCount(2, $this->getRequests());
        $this->assertRequest($this->getRequests()[1], 'POST', '#/payments/1001/capture$#');
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function plainLinkProvider(): iterable
    {
        yield 'no link on the payment' => [null];
        yield 'link with auto_capture false' => [['url' => 'https://payment.quickpay.net/payments/1001/window', 'auto_capture' => false]];
        yield 'link without the flag' => [['url' => 'https://payment.quickpay.net/payments/1001/window']];
    }

    #[Test]
    public function shouldCaptureThePartialAmountWhenTheDetailsCarryAnOverride(): void
    {
        $details = $this->details(['amount' => 1000, 'capture_amount' => 250]);

        $this->queueAuthorized(amount: 1000);
        $this->queuePayment([
            'state' => PaymentState::Processed->value,
            'balance' => 250,
            'operations' => [$this->operation(OperationType::Capture, amount: 250)],
        ]);

        $this->action()->execute($this->capture($details));

        self::assertSame(250, $this->decodeBody($this->getRequests()[1])['amount']);

        self::assertFalse(
            $details->offsetExists('capture_amount'),
            'The override must be consumed, or the next capture would silently be partial too',
        );
        self::assertSame(1000, $details['amount'], 'The full amount must be left alone');
    }

    /**
     * Instalments: Quickpay accepts repeated captures against one authorization (verified live,
     * 2026-08), so a payment that already has a capture is captured AGAIN when asked — as long as
     * the link is not the one doing the capturing.
     */
    #[Test]
    public function shouldCaptureAgainForAnInstalment(): void
    {
        $details = $this->details(['amount' => 1000, 'capture_amount' => 250]);

        $this->queuePayment([
            'state' => PaymentState::Processed->value,
            'balance' => 250,
            'operations' => [
                $this->operation(OperationType::Authorize, amount: 1000),
                $this->operation(OperationType::Capture, amount: 250),
            ],
            'link' => $this->link(autoCapture: false),
        ]);
        $this->queuePayment(['state' => PaymentState::Processed->value, 'balance' => 500, 'operations' => []]);

        $this->action()->execute($this->capture($details));

        self::assertCount(2, $this->getRequests());
        self::assertSame(250, $this->decodeBody($this->getRequests()[1])['amount']);
    }

    #[Test]
    public function shouldCaptureSynchronouslyWhenTheApiIsSynchronized(): void
    {
        $action = new CaptureAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->createApi(synchronized: true));

        $this->queueAuthorized();
        $this->queuePayment(['state' => PaymentState::Processed->value, 'operations' => [$this->operation(OperationType::Capture)]]);

        $action->execute($this->capture());

        $requests = $this->getRequests();
        self::assertCount(2, $requests);
        $this->assertRequest($requests[1], 'POST', '#/payments/1001/capture$#');
        self::assertSame('synchronized', $requests[1]->getUri()->getQuery());
    }

    /**
     * A failed capture surfaces, and the instruction survives for a retry.
     */
    #[Test]
    public function shouldLetACaptureRejectionSurfaceAndKeepTheOverride(): void
    {
        $details = $this->details(['amount' => 1000, 'capture_amount' => 250]);

        $this->queueAuthorized(amount: 1000);
        $this->queueResponse('{"message":"Validation error in capture"}', 400);

        try {
            $this->action()->execute($this->capture($details));
            self::fail('Expected the validation error to surface');
        } catch (ValidationException) {
            self::assertSame(250, $details['capture_amount']);
        }
    }

    // -- Guards -------------------------------------------------------------------------------

    #[Test]
    public function shouldThrowWhenThePaymentHasNotBeenCreated(): void
    {
        $details = $this->details();
        unset($details['quickpayPaymentId']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('quickpayPaymentId');

        try {
            $this->action()->execute($this->capture($details));
        } finally {
            self::assertCount(0, $this->getRequests(), 'No API call may be made for a payment that does not exist yet');
        }
    }

    private function action(bool $autoCapture = true): CaptureAction
    {
        $action = new CaptureAction();
        $action->setGateway($this->gateway);
        $action->setApi($autoCapture ? $this->api : $this->createApi(autoCapture: false));

        return $action;
    }

    /**
     * @param ArrayObject<string, mixed>|null $details
     */
    private function capture(?ArrayObject $details = null): Capture
    {
        /** @var Capture $capture */
        $capture = new static::$requestClass($details ?? $this->details());

        return $capture;
    }

    /**
     * Details with a preset callback_url, so no token is needed for the window path.
     *
     * @param array<string, mixed> $overrides
     *
     * @return ArrayObject<string, mixed>
     */
    private function details(array $overrides = []): ArrayObject
    {
        return new ArrayObject(array_replace([
            'quickpayPaymentId' => 1001,
            'amount' => 100,
            'continue_url' => 'theContinueUrl',
            'cancel_url' => 'theCancelUrl',
            'callback_url' => 'thePresetCallbackUrl',
        ], $overrides));
    }

    /**
     * An authorized payment whose link does NOT capture by itself — the shape a Capture must act on.
     */
    private function queueAuthorized(int $amount = 100): void
    {
        $this->queuePayment([
            'state' => PaymentState::New->value,
            'operations' => [$this->operation(OperationType::Authorize, amount: $amount)],
            'link' => $this->link(autoCapture: false),
        ]);
    }
}
