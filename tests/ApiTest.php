<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Setono\Payum\Quickpay\Api;
use Setono\Quickpay\Client\Endpoint\PaymentsEndpoint;

class ApiTest extends TestCase
{
    use ApiTestTrait;

    #[Test]
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

    #[Test]
    public function shouldReportTheSynchronizedFlagOfTheClient(): void
    {
        self::assertFalse($this->createApi()->isSynchronized());
        self::assertTrue($this->createApi(synchronized: true)->isSynchronized());
    }

    /**
     * The behavior options default to "off"/"unset": nothing restricted, no auto capture, no ids, `en`.
     */
    #[Test]
    public function shouldDefaultTheBehaviorOptionsToOff(): void
    {
        $api = new Api(client: $this->api->getClient(), privateKey: 'test-privatekey');

        self::assertNull($api->getPaymentMethods());
        self::assertFalse($api->isAutoCapture());
        self::assertSame('', $api->getOrderPrefix());
        self::assertSame('en', $api->getLanguage());
        self::assertNull($api->getAgreementId());
        self::assertNull($api->getBrandingId());
    }

    #[Test]
    public function shouldCreateCallbackValidatorBoundToThePrivateKey(): void
    {
        $validator = $this->api->createCallbackValidator();

        $body = '{"foo":"bar"}';
        self::assertTrue($validator->isValid($body, hash_hmac('sha256', $body, 'test-privatekey')));
        self::assertFalse($validator->isValid($body, 'not-the-checksum'));
    }
}
