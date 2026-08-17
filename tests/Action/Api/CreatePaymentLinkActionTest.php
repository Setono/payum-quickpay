<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Tests\Action\Api;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\LogicException;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Model\Token;
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Security\GenericTokenFactoryAwareInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Setono\Payum\Quickpay\Action\Api\CreatePaymentLinkAction;
use Setono\Payum\Quickpay\Api;
use Setono\Payum\Quickpay\Request\Api\CreatePaymentLink;
use Setono\Payum\Quickpay\Tests\ApiTestTrait;
use Setono\Quickpay\Client\Client;
use stdClass;

class CreatePaymentLinkActionTest extends TestCase
{
    use ApiTestTrait;

    /**
     * This is the ONE action that mints a token — the notify token for `callback_url` — and so the
     * one contact with payum/core's deprecated GenericTokenFactoryInterface (see #3). The public
     * actions must not implement it; their tests pin that side.
     */
    #[Test]
    public function shouldBeTheOnlyTokenFactoryAwareAction(): void
    {
        self::assertTrue((new ReflectionClass(CreatePaymentLinkAction::class))->implementsInterface(GenericTokenFactoryAwareInterface::class));
    }

    #[Test]
    public function shouldSupportCreatePaymentLinkWithAnArrayAccessModelOnly(): void
    {
        $action = new CreatePaymentLinkAction();

        self::assertTrue($action->supports(new CreatePaymentLink(new ArrayObject([]), autoCapture: false)));
        self::assertFalse($action->supports(new CreatePaymentLink(new stdClass(), autoCapture: false)));
        self::assertFalse($action->supports(new stdClass()));

        $this->expectException(RequestNotSupportedException::class);
        $action->execute(new stdClass());
    }

    #[Test]
    public function shouldCreateTheLinkFromTheTokenAndRedirectToIt(): void
    {
        $token = new Token();
        $token->setGatewayName('quickpay');
        $token->setDetails(['identity' => 'of-the-payment']);

        $details = new ArrayObject([
            'quickpayPaymentId' => 1001,
            'amount' => 100,
            'continue_url' => 'theContinueUrl',
            'cancel_url' => 'theCancelUrl',
        ]);

        $this->queueResponse('{"url":"https://payment.quickpay.net/payments/1001/payment-window"}');

        try {
            $this->action()->execute(new CreatePaymentLink($details, autoCapture: false, token: $token));
            self::fail('An HttpRedirect reply should have been thrown');
        } catch (HttpRedirect $redirect) {
            self::assertSame('https://payment.quickpay.net/payments/1001/payment-window', $redirect->getUrl());
        }

        // The callback url is the notify token minted for the token's gateway + model.
        self::assertSame('https://shop.example/notify?payum_token=stub-notify', $details['callback_url']);
        self::assertSame([['quickpay', ['identity' => 'of-the-payment']]], $this->tokenFactory->notifyTokensCreated);

        // The link request shape.
        $requests = $this->getRequests();
        self::assertCount(1, $requests);
        $this->assertRequest($requests[0], 'PUT', '#/payments/1001/link$#');

        $body = $this->decodeBody($requests[0]);
        self::assertSame(100, $body['amount']);
        self::assertSame('theContinueUrl', $body['continue_url']);
        self::assertSame('theCancelUrl', $body['cancel_url']);
        self::assertSame('https://shop.example/notify?payum_token=stub-notify', $body['callback_url']);
        self::assertSame('en', $body['language']);
        self::assertSame('visa', $body['payment_methods']);
        self::assertFalse($body['auto_capture']);
        self::assertSame(266017, $body['agreement_id']);
    }

    /**
     * The request, not the gateway option, decides whether the window captures: Capture asks for it,
     * Authorize does not. Pin both values on the wire.
     */
    #[Test]
    #[DataProvider('autoCaptureProvider')]
    public function shouldPutTheRequestedAutoCaptureOnTheLink(bool $autoCapture): void
    {
        $this->queueResponse('{"url":"https://payment.quickpay.net/payments/1001/payment-window"}');

        try {
            $this->action()->execute(new CreatePaymentLink($this->presetDetails(), autoCapture: $autoCapture));
        } catch (HttpRedirect) {
        }

        self::assertSame($autoCapture, $this->decodeBody($this->getRequests()[0])['auto_capture']);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function autoCaptureProvider(): iterable
    {
        yield 'capture at authorization' => [true];
        yield 'authorize only' => [false];
    }

    /**
     * A consumer that routes callbacks itself presets `callback_url` and executes without a token.
     * That path must not need the token factory at all.
     */
    #[Test]
    public function shouldCreateTheLinkWithoutATokenWhenTheCallbackUrlIsPreset(): void
    {
        $action = new CreatePaymentLinkAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);
        // Deliberately no setGenericTokenFactory().

        $this->queueResponse('{"url":"https://payment.quickpay.net/payments/1001/payment-window"}');

        try {
            $action->execute(new CreatePaymentLink($this->presetDetails(), autoCapture: false));
            self::fail('An HttpRedirect reply should have been thrown');
        } catch (HttpRedirect $redirect) {
            self::assertSame('https://payment.quickpay.net/payments/1001/payment-window', $redirect->getUrl());
        }

        self::assertSame('thePresetCallbackUrl', $this->decodeBody($this->getRequests()[0])['callback_url']);
        self::assertSame([], $this->tokenFactory->notifyTokensCreated);
    }

    #[Test]
    public function shouldThrowBeforeAnyRequestWhenARequiredDetailIsMissing(): void
    {
        // No callback_url, and no token to mint one from.
        $details = new ArrayObject([
            'quickpayPaymentId' => 1001,
            'amount' => 100,
            'continue_url' => 'theContinueUrl',
            'cancel_url' => 'theCancelUrl',
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('callback_url');

        try {
            $this->action()->execute(new CreatePaymentLink($details, autoCapture: false));
        } finally {
            self::assertCount(0, $this->getRequests(), 'The link request must not be issued');
        }
    }

    #[Test]
    public function shouldThrowWhenThePaymentHasNotBeenCreated(): void
    {
        $details = $this->presetDetails();
        unset($details['quickpayPaymentId']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('quickpayPaymentId');

        try {
            $this->action()->execute(new CreatePaymentLink($details, autoCapture: false, token: new Token()));
        } finally {
            self::assertCount(0, $this->getRequests());
            self::assertSame([], $this->tokenFactory->notifyTokensCreated, 'No token may be minted for a payment that does not exist');
        }
    }

    #[Test]
    public function shouldThrowWhenQuickpayReturnsNoLinkUrl(): void
    {
        $this->queueResponse('{"url":null}');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('did not return a payment link url');

        $this->action()->execute(new CreatePaymentLink($this->presetDetails(), autoCapture: false));
    }

    /**
     * `branding_id` is optional, so if it silently stopped being read the link would simply be
     * created without it and Quickpay would fall back to the account default — the same failure mode
     * the factory tests guard against for `agreement`. Pin that the option reaches the wire.
     */
    #[Test]
    public function shouldPassTheBrandingIdToTheLink(): void
    {
        $api = new Api(
            client: new Client('test-apikey', $this->httpClient),
            privateKey: 'test-privatekey',
            brandingId: 424242,
        );

        $action = new CreatePaymentLinkAction();
        $action->setGateway($this->gateway);
        $action->setApi($api);

        $this->queueResponse('{"url":"https://payment.quickpay.net/payments/1001/payment-window"}');

        try {
            $action->execute(new CreatePaymentLink($this->presetDetails(), autoCapture: false));
        } catch (HttpRedirect) {
        }

        self::assertSame(424242, $this->decodeBody($this->getRequests()[0])['branding_id']);
    }

    private function action(): CreatePaymentLinkAction
    {
        $action = new CreatePaymentLinkAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);
        $action->setGenericTokenFactory($this->tokenFactory);

        return $action;
    }

    /**
     * @return ArrayObject<string, mixed>
     */
    private function presetDetails(): ArrayObject
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
