<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Tests;

use PHPUnit\Framework\TestCase;
use Setono\Payum\QuickPay\Api;
use Setono\Quickpay\Client\Endpoint\PaymentsEndpoint;

class ApiTest extends TestCase
{
    use ApiTestTrait;

    /**
     * @test
     */
    public function shouldExposeConfiguredOptions(): void
    {
        self::assertSame('test-privatekey', $this->api->getPrivateKey());
        self::assertSame('ut', $this->api->getOrderPrefix());
        self::assertSame('visa', $this->api->getPaymentMethods());
        self::assertSame('en', $this->api->getLanguage());
        self::assertTrue($this->api->isAutoCapture());
        self::assertFalse($this->api->isSynchronized());
        self::assertSame(266017, $this->api->getAgreementId());
        self::assertNull($this->api->getBrandingId());
        self::assertInstanceOf(PaymentsEndpoint::class, $this->api->payments());
    }

    /**
     * @test
     */
    public function shouldReturnNullPaymentMethodsWhenEmpty(): void
    {
        $api = new Api(client: $this->api->getClient(), privateKey: 'test-privatekey', paymentMethods: '');

        self::assertNull($api->getPaymentMethods());
    }

    /**
     * @test
     */
    public function shouldCreateCallbackValidatorBoundToThePrivateKey(): void
    {
        $validator = $this->api->createCallbackValidator();

        $body = '{"foo":"bar"}';
        self::assertTrue($validator->isValid($body, hash_hmac('sha256', $body, 'test-privatekey')));
        self::assertFalse($validator->isValid($body, 'not-the-checksum'));
    }
}
