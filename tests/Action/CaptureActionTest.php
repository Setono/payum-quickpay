<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Tests\Action;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\Http\HttpException;
use Payum\Core\Model\Token;
use Payum\Core\Request\Authorize;
use Payum\Core\Request\Capture;
use Payum\Core\Request\Convert;
use Payum\Core\Security\GenericTokenFactoryAwareInterface;
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
     * @throws \ReflectionException
     * @throws \Exception
     */
    public function shouldImplementGenericTokenFactoryAwareInterface(): void
    {
        $rc = new \ReflectionClass($this->actionClass);

        $this->assertTrue($rc->implementsInterface(GenericTokenFactoryAwareInterface::class));
    }

    /**
     * @test
     *
     * @throws \Exception
     */
    public function shouldCapturePayment(): void
    {
        $payment = $this->createPayment();

        $token = new Token();
        $token->setTargetUrl('theCallbackUrl');
        $token->setAfterUrl('theContinueUrl');
        $token->setGatewayName('quickpay');

        $convert = new Convert($payment, 'array', $token);

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

        // Try capture from incorrect state
        try {
            $action->execute($capture);
        } catch (HttpException $e) {
            $this->assertStringStartsWith('Validation error', json_decode($e->getMessage())->message);
        }

        // Authorize payment with test card
        $details['card'] = $this->getTestCard()->toArray();
        $details['acquirer'] = 'clearhaus';
        $this->api->authorizePayment($details['quickpayPayment'], $details);
        $quickpayPayment = $this->api->getPayment($details);
        $this->assertEquals(QuickpayPayment::STATE_INITIAL, $quickpayPayment->getState());

        // Capture again
        $action->execute($capture);

        $quickpayPayment = $this->api->getPayment(new ArrayObject([
            'quickpayPaymentId' => $quickpayPayment->getId(),
        ]));

        $this->assertEquals(QuickpayPayment::STATE_PROCESSED, $quickpayPayment->getState());
        $this->assertEquals(QuickPayPaymentOperation::TYPE_CAPTURE, $quickpayPayment->getLatestOperation()->getType());
        $this->assertEquals(QuickPayPaymentOperation::STATUS_CODE_APPROVED, $quickpayPayment->getLatestOperation()->getStatusCode());
    }
}
