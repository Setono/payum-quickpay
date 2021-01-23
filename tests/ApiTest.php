<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Tests;

use Exception;
use GuzzleHttp\Psr7\Response;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\LogicException;
use PHPUnit\Framework\TestCase;
use Setono\Payum\QuickPay\Api;

class ApiTest extends TestCase
{
    use ApiTestTrait;

    /**
     * @test
     *
     * @throws Exception
     */
    public function shouldValidateChecksum(): void
    {
        $body = 'This is a fine looking body';
        $checksum = Api::checksum($body, '1234');
        $response = new Response(200, ['QuickPay-Checksum-Sha256' => $checksum], $body);
        Api::assertValidResponse($response, '1234');

        try {
            Api::assertValidResponse($response, '12345');
        } catch (LogicException $le) {
            self::assertEquals('Invalid checksum', $le->getMessage());
        }
    }

    /**
     * @test
     *
     * @throws Exception
     */
    public function getPaymentShouldFailOnInvalidPaymentInfo(): void
    {
        try {
            $this->api->getPayment(new ArrayObject(), false);
        } catch (LogicException $le) {
            self::assertEquals('Payment does not exist', $le->getMessage());
        }
    }
}
