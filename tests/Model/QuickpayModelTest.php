<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Tests\Model;

use DateTime;
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
     */
    public function quickpayEmptyPayment(): void
    {
        $data = (object) [
            'id' => 100,
            'order_id' => 't100',
            'operations' => [],
            'currency' => 'DKK',
            'fee' => null,
            'state' => QuickpayPayment::STATE_NEW,
        ];
        $quickpayPayment = QuickpayPayment::createFromObject($data);

        self::assertEquals($data->id, $quickpayPayment->getId());
        self::assertEquals($data->currency, $quickpayPayment->getCurrency());
        self::assertEquals($data->order_id, $quickpayPayment->getOrderId());
        self::assertGreaterThanOrEqual(0, $quickpayPayment->getAuthorizedAmount());
        self::assertEquals(QuickPayPayment::STATE_NEW, $quickpayPayment->getState());
        self::assertEquals($data->fee, $quickpayPayment->getFee());
        self::assertNull($quickpayPayment->getLatestOperation());
    }

    /**
     * @test
     */
    public function quickpayPayment(): void
    {
        $this->queueResponse('[' . $this->paymentJson([
            'id' => 1001,
            'state' => QuickPayPayment::STATE_PROCESSED,
        ]) . ']');
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
     */
    public function quickpayPaymentOperation(): void
    {
        $this->queuePayment(['id' => 1001, 'state' => QuickPayPayment::STATE_INITIAL]);
        $quickpayPayment = $this->api->getPayment(new ArrayObject(['payment' => $this->createPayment()]));

        $this->queuePayment(['id' => 1001, 'state' => QuickPayPayment::STATE_NEW]);
        $this->api->authorizePayment($quickpayPayment, new ArrayObject([
            'card' => $this->getTestCard()->toArray(),
            'acquirer' => 'clearhaus',
            'amount' => 100,
        ]));

        $this->queuePayment([
            'id' => 1001,
            'state' => QuickPayPayment::STATE_NEW,
            'operations' => [$this->operation(QuickPayPaymentOperation::TYPE_AUTHORIZE, QuickPayPaymentOperation::STATUS_CODE_APPROVED, 100)],
        ]);
        $quickpayPayment = $this->api->getPayment(new ArrayObject([
            'quickpayPaymentId' => $quickpayPayment->getId(),
        ]));

        $quickpayPaymentOperation = $quickpayPayment->getLatestOperation();

        self::assertInstanceOf(QuickPayPaymentOperation::class, $quickpayPaymentOperation);
        self::assertGreaterThan(0, $quickpayPaymentOperation->getId());
        self::assertEquals(QuickPayPaymentOperation::TYPE_AUTHORIZE, $quickpayPaymentOperation->getType());
        self::assertEquals(QuickPayPaymentOperation::STATUS_CODE_APPROVED, $quickpayPaymentOperation->getStatusCode());
        self::assertEquals(100, $quickpayPaymentOperation->getAmount());
    }

    /**
     * @test
     */
    public function quickpayPaymentLink(): void
    {
        $this->queuePayment(['id' => 1001, 'state' => QuickPayPayment::STATE_INITIAL]);
        $quickpayPayment = $this->api->getPayment(new ArrayObject(['payment' => $this->createPayment()]));

        $this->queueResponse('{"url":"https://payment.quickpay.net/payments/1001/payment-window"}');
        $quickpayPaymentLink = $this->api->createPaymentLink($quickpayPayment, new ArrayObject(['continue_url' => '-', 'cancel_url' => '-', 'callback_url' => '-', 'amount' => 100]));

        self::assertNotEmpty($quickpayPaymentLink->getUrl());
    }
}
