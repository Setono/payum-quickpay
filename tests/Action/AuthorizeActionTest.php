<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Tests\Action;

use Exception;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Model\Token;
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Request\Authorize;
use Payum\Core\Request\Convert;
use Payum\Core\Security\GenericTokenFactoryAwareInterface;
use Payum\Core\Security\GenericTokenFactoryInterface;
use ReflectionClass;
use ReflectionException;
use Setono\Payum\QuickPay\Action\AuthorizeAction;
use Setono\Payum\QuickPay\Action\ConvertPaymentAction;
use Setono\Payum\QuickPay\Model\QuickPayPaymentOperation;

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

        $this->assertTrue($rc->implementsInterface(GenericTokenFactoryAwareInterface::class));
    }

    /**
     * @test
     *
     * @throws Exception
     */
    public function shouldRedirectToPaymentLink(): void
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

        /** @var Authorize $authorize */
        $authorize = new $this->requestClass($token);
        $authorize->setModel($details);

        $tokenFactory = $this->createMock(GenericTokenFactoryInterface::class);
        $tokenFactory
            ->expects($this->once())
            ->method('createNotifyToken')
            ->with('quickpay', $this->identicalTo($details))
            ->willReturn($token)
        ;

        /** @var AuthorizeAction $action */
        $action = new $this->actionClass();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);
        $action->setGenericTokenFactory($tokenFactory);

        try {
            $action->execute($authorize);
        } catch (HttpRedirect $redirect) {
            $this->assertStringStartsWith('https://payment.quickpay.net/payments/', $redirect->getUrl());
        }

        // Authorize payment with test card
        $details['card'] = $this->getTestCard()->toArray();
        $details['acquirer'] = 'clearhaus';
        $quickpayPayment = $this->api->authorizePayment($details['quickpayPayment'], $details);

        // Validate that we received the payment from the operation
        $this->assertEquals($details['quickpayPayment']->getId(), $quickpayPayment->getId());

        // Reload payment to get the status of the authorize operation
        sleep(1);
        $quickpayPayment = $this->api->getPayment(new ArrayObject(['quickpayPaymentId' => $quickpayPayment->getId()]));

        // Validate authorize operation
        $latestOperation = $quickpayPayment->getLatestOperation();
        $this->assertEquals(QuickPayPaymentOperation::TYPE_AUTHORIZE, $latestOperation->getType());
        $this->assertEquals(QuickPayPaymentOperation::STATUS_CODE_APPROVED, $latestOperation->getStatusCode());
        $this->assertEquals($details['amount'], $latestOperation->getAmount());
    }
}
