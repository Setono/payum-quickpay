<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Tests\Action;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\Http\HttpException;
use Payum\Core\Model\Token;
use Payum\Core\Request\Capture;
use Payum\Core\Request\Convert;
use Payum\Core\Security\GenericTokenFactoryAwareInterface;
use ReflectionClass;
use ReflectionException;
use Setono\Payum\QuickPay\Action\CaptureAction;
use Setono\Payum\QuickPay\Action\ConvertPaymentAction;
use Setono\Payum\QuickPay\Model\QuickPayPayment;
use Setono\Payum\QuickPay\Model\QuickPayPaymentOperation;

class CaptureActionTest extends ActionTestAbstract
{
    protected $requestClass = Capture::class;

    protected $actionClass = CaptureAction::class;

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
     * @test
     */
    public function shouldCapturePayment(): void
    {
        $payment = $this->createPayment();

        $token = new Token();
        $token->setTargetUrl('theCallbackUrl');
        $token->setAfterUrl('theContinueUrl');
        $token->setGatewayName('quickpay');

        $convert = new Convert($payment, 'array', $token);

        // ConvertPaymentAction creates the QuickPay payment.
        $this->queuePayment(['id' => 1001, 'state' => QuickPayPayment::STATE_INITIAL]);

        $convertPaymentAction = new ConvertPaymentAction();
        $convertPaymentAction->setGateway($this->gateway);
        $convertPaymentAction->setApi($this->api);
        $convertPaymentAction->execute($convert);

        $payment->setDetails($convert->getResult());
        $details = ArrayObject::ensureArrayObject($payment->getDetails());
        $token->setDetails($details);

        /** @var Capture $capture */
        $capture = new $this->requestClass($token);
        $capture->setModel($details);

        /** @var CaptureAction $action */
        $action = new $this->actionClass();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);

        // Capturing before the payment is authorized fails with a validation error.
        $this->queueResponse('{"message":"Validation error in capture"}', 400);

        try {
            $action->execute($capture);
        } catch (HttpException $e) {
            $body = json_decode((string) $e->getResponse()->getBody(), false, 512, \JSON_THROW_ON_ERROR);
            self::assertStringStartsWith('Validation error', $body->message);
        }

        // Authorize the payment with the test card.
        $details['card'] = $this->getTestCard()->toArray();
        $details['acquirer'] = 'clearhaus';
        $this->queuePayment([
            'state' => QuickPayPayment::STATE_NEW,
            'operations' => [$this->operation(QuickPayPaymentOperation::TYPE_AUTHORIZE)],
        ]);
        $this->api->authorizePayment($details['quickpayPayment'], $details);

        $quickpayPayment = $this->api->getPayment($details);
        self::assertEquals(QuickPayPayment::STATE_INITIAL, $quickpayPayment->getState());

        // Capture again, this time it succeeds.
        $this->queuePayment([
            'state' => QuickPayPayment::STATE_PROCESSED,
            'operations' => [$this->operation(QuickPayPaymentOperation::TYPE_CAPTURE)],
        ]);
        $action->execute($capture);

        // Reload the payment to assert the captured state.
        $this->queuePayment([
            'id' => 1001,
            'state' => QuickPayPayment::STATE_PROCESSED,
            'operations' => [$this->operation(QuickPayPaymentOperation::TYPE_CAPTURE)],
        ]);
        $quickpayPayment = $this->api->getPayment(new ArrayObject([
            'quickpayPaymentId' => $quickpayPayment->getId(),
        ]));

        self::assertEquals(QuickPayPayment::STATE_PROCESSED, $quickpayPayment->getState());
        self::assertEquals(QuickPayPaymentOperation::TYPE_CAPTURE, $quickpayPayment->getLatestOperation()->getType());
        self::assertEquals(QuickPayPaymentOperation::STATUS_CODE_APPROVED, $quickpayPayment->getLatestOperation()->getStatusCode());
    }
}
