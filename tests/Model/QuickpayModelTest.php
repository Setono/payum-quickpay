<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Tests\Model;

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
     * @throws \Exception
     */
    public function quickpayCard(): void
    {
        $exp = (new \DateTime())->format('ym');
        $card = $this->getTestCard();

        $this->assertEquals(1000000000000008, $card->getNumber());
        $this->assertEquals($exp, $card->getExpiration());
        $this->assertEquals(123, $card->getCvd());
    }

    /**
     * @test
     *
     * @throws \Exception
     */
    public function quickpayPayment(): void
    {
        $data = json_decode(json_encode([
            'id' => 100,
            'order_id' => 't100',
            'operations' => [],
            'currency' => 'DKK',
            'state' => QuickpayPayment::STATE_NEW,
        ]));
        $quickpayPayment = QuickpayPayment::createFromObject($data);

        $this->assertQuickpayPayment($quickpayPayment);
    }

    /**
     * @test
     *
     * @throws \Exception
     */
    public function quickpayEmptyPayment(): void
    {
        $quickpayPayments = $this->api->getPayments(new ArrayObject(['page_size' => 1, 'state' => QuickpayPayment::STATE_PROCESSED]));

        $this->assertCount(1, $quickpayPayments);

        $this->assertQuickpayPayment($quickpayPayments[0]);
    }

    /**
     * @param QuickPayPayment $quickpayPayment
     *
     * @throws \Exception
     */
    private function assertQuickpayPayment(QuickPayPayment $quickpayPayment): void
    {
        $this->assertGreaterThan(0, $quickpayPayment->getId());
        $this->assertGreaterThanOrEqual(0, $quickpayPayment->getAuthorizedAmount());
        $this->assertEquals(3, strlen($quickpayPayment->getCurrency()));
        $this->assertNotEmpty($quickpayPayment->getOrderId());
        $this->assertContains($quickpayPayment->getState(), [
            QuickPayPayment::STATE_INITIAL,
            QuickPayPayment::STATE_NEW,
            QuickPayPayment::STATE_PROCESSED,
            QuickPayPayment::STATE_PENDING,
            QuickPayPayment::STATE_REJECTED,
        ]);
        if (null !== $quickpayPayment->getLatestOperation()) {
            $this->assertInstanceOf(QuickPayPaymentOperation::class, $quickpayPayment->getLatestOperation());
        }
    }

    /**
     * @test
     *
     * @throws \Exception
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
            'amount' => 1,
        ]));

        $quickpayPayment = $this->api->getPayment(new ArrayObject([
            'quickpayPaymentId' => $quickpayPayment->getId(),
        ]));

        $quickpayPaymentOperation = $quickpayPayment->getLatestOperation();

        $this->assertInstanceOf(QuickPayPaymentOperation::class, $quickpayPaymentOperation);
        $this->assertGreaterThan(0, $quickpayPaymentOperation->getId());
        $this->assertContains($quickpayPaymentOperation->getType(), [
            QuickPayPaymentOperation::TYPE_AUTHORIZE,
            QuickPayPaymentOperation::TYPE_CAPTURE,
            QuickPayPaymentOperation::TYPE_REFUND,
            QuickPayPaymentOperation::TYPE_CANCEL,
        ]);
        $this->assertEquals(QuickPayPaymentOperation::STATUS_CODE_APPROVED, $quickpayPaymentOperation->getStatusCode());
        $this->assertGreaterThan(0, $quickpayPaymentOperation->getAmount());
    }

    /**
     * @test
     *
     * @throws \Exception
     */
    public function quickpayPaymentLink(): void
    {
        $quickpayPayment = $this->api->getPayment(new ArrayObject(['payment' => $this->createPayment()]));

        $quickpayPaymentLink = $this->api->createPaymentLink($quickpayPayment, new ArrayObject(['continue_url' => '-', 'cancel_url' => '-', 'callback_url' => '-', 'amount' => 100]));

        $this->assertNotEmpty($quickpayPaymentLink->getUrl());
    }
}
