<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Tests\Action;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\LogicException;
use Payum\Core\Model\Token;
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Request\Authorize;
use Payum\Core\Security\GenericTokenFactoryAwareInterface;
use Payum\Core\Security\GenericTokenFactoryInterface;
use ReflectionClass;
use ReflectionException;
use Setono\Payum\Quickpay\Action\AuthorizeAction;
use Setono\Payum\Quickpay\Api;
use Setono\Quickpay\Client\Client;

class AuthorizeActionTest extends ActionTestAbstract
{
    protected $requestClass = Authorize::class;

    protected $actionClass = AuthorizeAction::class;

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
     * A model without a quickpayPaymentId has no payment to create a link for. The guard throws
     * before a notify token is minted and before any HTTP happens — without it, `(int) null = 0`
     * would reach the API as `PUT /payments/0/link`.
     *
     * @test
     */
    public function shouldThrowWhenThePaymentHasNotBeenCreated(): void
    {
        $details = new ArrayObject([
            'amount' => 100,
            'continue_url' => 'theContinueUrl',
            'cancel_url' => 'theContinueUrl',
            'callback_url' => 'theCallbackUrl',
        ]);

        /** @var Authorize $authorize */
        $authorize = new $this->requestClass($details);

        $action = new AuthorizeAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('quickpayPaymentId');

        try {
            $action->execute($authorize);
        } finally {
            self::assertCount(0, $this->getRequests(), 'No API call may be made for a payment that does not exist yet');
        }
    }

    /**
     * @test
     */
    public function shouldCreatePaymentLinkAndRedirectToIt(): void
    {
        $token = new Token();
        $token->setTargetUrl('theCallbackUrl');
        $token->setAfterUrl('theContinueUrl');
        $token->setGatewayName('quickpay');

        $details = new ArrayObject([
            'quickpayPaymentId' => 1001,
            'amount' => 100,
            'continue_url' => 'theContinueUrl',
            'cancel_url' => 'theContinueUrl',
        ]);
        $token->setDetails($details);

        /** @var Authorize $authorize */
        $authorize = new $this->requestClass($token);
        $authorize->setModel($details);

        $tokenFactory = $this->prophesize(GenericTokenFactoryInterface::class);
        $tokenFactory->createNotifyToken('quickpay', $details)->shouldBeCalledOnce()->willReturn($token);

        $action = new AuthorizeAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);
        $action->setGenericTokenFactory($tokenFactory->reveal());

        $this->queueResponse('{"url":"https://payment.quickpay.net/payments/1001/payment-window"}');

        try {
            $action->execute($authorize);
            self::fail('An HttpRedirect reply should have been thrown');
        } catch (HttpRedirect $redirect) {
            self::assertSame('https://payment.quickpay.net/payments/1001/payment-window', $redirect->getUrl());
        }

        // The callback url is built from the notify token.
        self::assertSame('theCallbackUrl', $details['callback_url']);

        // The link request shape.
        $requests = $this->getRequests();
        self::assertCount(1, $requests);
        $this->assertRequest($requests[0], 'PUT', '#/payments/1001/link$#');

        $body = $this->decodeBody($requests[0]);
        self::assertSame(100, $body['amount']);
        self::assertSame('theContinueUrl', $body['continue_url']);
        self::assertSame('theContinueUrl', $body['cancel_url']);
        self::assertSame('theCallbackUrl', $body['callback_url']);
        self::assertSame('en', $body['language']);
        self::assertSame('visa', $body['payment_methods']);
        self::assertTrue($body['auto_capture']);
        self::assertSame(266017, $body['agreement_id']);
    }

    /**
     * A consumer that routes callbacks itself presets `callback_url` and executes Authorize without
     * a token. That path must not need the token factory at all — the action only mints a notify
     * token when the request carries a token to mint it from.
     *
     * @test
     */
    public function shouldCreateTheLinkWithoutATokenWhenTheCallbackUrlIsPreset(): void
    {
        $details = new ArrayObject([
            'quickpayPaymentId' => 1001,
            'amount' => 100,
            'continue_url' => 'theContinueUrl',
            'cancel_url' => 'theCancelUrl',
            'callback_url' => 'thePresetCallbackUrl',
        ]);

        /** @var Authorize $authorize */
        $authorize = new $this->requestClass($details);

        $action = new AuthorizeAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);
        // Deliberately no setGenericTokenFactory().

        $this->queueResponse('{"url":"https://payment.quickpay.net/payments/1001/payment-window"}');

        try {
            $action->execute($authorize);
            self::fail('An HttpRedirect reply should have been thrown');
        } catch (HttpRedirect $redirect) {
            self::assertSame('https://payment.quickpay.net/payments/1001/payment-window', $redirect->getUrl());
        }

        $requests = $this->getRequests();
        self::assertCount(1, $requests);
        self::assertSame('thePresetCallbackUrl', $this->decodeBody($requests[0])['callback_url']);
    }

    /**
     * @test
     */
    public function shouldThrowBeforeAnyRequestWhenARequiredDetailIsMissing(): void
    {
        // No callback_url, and no token to mint one from.
        $details = new ArrayObject([
            'quickpayPaymentId' => 1001,
            'amount' => 100,
            'continue_url' => 'theContinueUrl',
            'cancel_url' => 'theCancelUrl',
        ]);

        /** @var Authorize $authorize */
        $authorize = new $this->requestClass($details);

        $action = new AuthorizeAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('callback_url');

        try {
            $action->execute($authorize);
        } finally {
            self::assertCount(0, $this->getRequests(), 'The link request must not be issued');
        }
    }

    /**
     * @test
     */
    public function shouldThrowWhenQuickpayReturnsNoLinkUrl(): void
    {
        $details = new ArrayObject([
            'quickpayPaymentId' => 1001,
            'amount' => 100,
            'continue_url' => 'theContinueUrl',
            'cancel_url' => 'theCancelUrl',
            'callback_url' => 'theCallbackUrl',
        ]);

        /** @var Authorize $authorize */
        $authorize = new $this->requestClass($details);

        $action = new AuthorizeAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        $this->queueResponse('{"url":null}');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('did not return a payment link url');

        $action->execute($authorize);
    }

    /**
     * `branding_id` is optional, so if it silently stopped being read the link would simply be
     * created without it and Quickpay would fall back to the account default — the same failure mode
     * the factory tests guard against for `agreement`. Pin that the option reaches the wire.
     *
     * @test
     */
    public function shouldPassTheBrandingIdToTheLink(): void
    {
        $api = new Api(
            client: new Client('test-apikey', $this->httpClient),
            privateKey: 'test-privatekey',
            brandingId: 424242,
        );

        $details = new ArrayObject([
            'quickpayPaymentId' => 1001,
            'amount' => 100,
            'continue_url' => 'theContinueUrl',
            'cancel_url' => 'theCancelUrl',
            'callback_url' => 'theCallbackUrl',
        ]);

        /** @var Authorize $authorize */
        $authorize = new $this->requestClass($details);

        $action = new AuthorizeAction();
        $action->setGateway($this->gateway);
        $action->setApi($api);

        $this->queueResponse('{"url":"https://payment.quickpay.net/payments/1001/payment-window"}');

        try {
            $action->execute($authorize);
            self::fail('An HttpRedirect reply should have been thrown');
        } catch (HttpRedirect) {
        }

        self::assertSame(424242, $this->decodeBody($this->getRequests()[0])['branding_id']);
    }
}
