<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Tests\Model;

use DateTime;
use Exception;
use Payum\Core\Bridge\Spl\ArrayObject;
use PHPUnit\Framework\TestCase;
use Setono\Payum\QuickPay\Model\QuickPayPayment;
use Setono\Payum\QuickPay\Model\QuickPayPaymentOperation;
use Setono\Payum\QuickPay\Tests\ApiTestTrait;

class QuickpayModelTest extends TestCase
{
    use ApiTestTrait;

    /**
     * @test
     *
     * @throws Exception
     */
    public function quickpayCard(): void
    {
        $exp = (new DateTime())->format('ym');
        $card = $this->getTestCard();

        self::assertEquals(1000000000000008, $card->getNumber());
        self::assertEquals($exp, $card->getExpiration());
        self::assertEquals(123, $card->getCvd());
    }

    /**
     * @test
     *
     * @throws Exception
     */
    public function quickpayEmptyPayment(): void
    {
        $data = (object) [
            'id' => 100,
            'order_id' => 't100',
            'operations' => [],
            'currency' => 'DKK',
            'state' => QuickpayPayment::STATE_NEW,
        ];
        $quickpayPayment = QuickpayPayment::createFromObject($data);

        self::assertEquals($data->id, $quickpayPayment->getId());
        self::assertEquals($data->currency, $quickpayPayment->getCurrency());
        self::assertEquals($data->order_id, $quickpayPayment->getOrderId());
        self::assertGreaterThanOrEqual(0, $quickpayPayment->getAuthorizedAmount());
        self::assertEquals(QuickPayPayment::STATE_NEW, $quickpayPayment->getState());
        self::assertNull($quickpayPayment->getLatestOperation());
    }

    /**
     * @test
     *
     * @throws Exception
     */
    public function quickpayPayment(): void
    {
        $quickpayPayments = $this->api->getPayments(new ArrayObject(['page_size' => 1, 'state' => QuickpayPayment::STATE_PROCESSED]));

        self::assertCount(1, $quickpayPayments);

        $quickpayPayment = $quickpayPayments[0];

        self::assertGreaterThan(0, $quickpayPayment->getId());
        self::assertGreaterThanOrEqual(0, $quickpayPayment->getAuthorizedAmount());
        self::assertEquals(3, \strlen($quickpayPayment->getCurrency()));
        self::assertNotEmpty($quickpayPayment->getOrderId());
        self::assertEquals(QuickPayPayment::STATE_PROCESSED, $quickpayPayment->getState());
        if (null !== $quickpayPayment->getLatestOperation()) {
            self::assertInstanceOf(QuickPayPaymentOperation::class, $quickpayPayment->getLatestOperation());
        }
    }

    /**
     * @test
     *
     * @throws Exception
     */
    public function quickpayPaymentOperation(): void
    {
        $params = new ArrayObject([
            'payment' => $this->createPayment(),
        ]);
        $quickpayPayment = $this->api->getPayment($params);

        $this->api->authorizePayment($quickpayPayment, new ArrayObject([
            'card' => $this->getTestCard()->toArray(),
            'acquirer' => 'clearhaus',
            'amount' => $params['payment']->getTotalAmount(),
        ]));

        sleep(1);
        $quickpayPayment = $this->api->getPayment(new ArrayObject([
            'quickpayPaymentId' => $quickpayPayment->getId(),
        ]));

        $quickpayPaymentOperation = $quickpayPayment->getLatestOperation();

        self::assertInstanceOf(QuickPayPaymentOperation::class, $quickpayPaymentOperation);
        self::assertGreaterThan(0, $quickpayPaymentOperation->getId());
        self::assertEquals(QuickPayPaymentOperation::TYPE_AUTHORIZE, $quickpayPaymentOperation->getType());
        self::assertEquals(QuickPayPaymentOperation::STATUS_CODE_APPROVED, $quickpayPaymentOperation->getStatusCode());
        self::assertEquals($params['payment']->getTotalAmount(), $quickpayPaymentOperation->getAmount());
    }

    /**
     * @test
     *
     * @throws Exception
     */
    public function quickpayPaymentLink(): void
    {
        $quickpayPayment = $this->api->getPayment(new ArrayObject(['payment' => $this->createPayment()]));

        $quickpayPaymentLink = $this->api->createPaymentLink($quickpayPayment, new ArrayObject(['continue_url' => '-', 'cancel_url' => '-', 'callback_url' => '-', 'amount' => 100]));

        self::assertNotEmpty($quickpayPaymentLink->getUrl());
    }
}
