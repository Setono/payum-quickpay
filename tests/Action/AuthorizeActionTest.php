<?php

namespace Setono\Payum\QuickPay\Tests\Action;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Model\Token;
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Request\Authorize;
use Payum\Core\Request\Convert;
use Payum\Core\Security\GenericTokenFactoryAwareInterface;
use Payum\Core\Security\GenericTokenFactoryInterface;
use Setono\Payum\QuickPay\Action\AuthorizeAction;
use Setono\Payum\QuickPay\Action\ConvertPaymentAction;

class AuthorizeActionTest extends ActionTestAbstract
{
    protected $requestClass = Authorize::class;

    protected $actionClass = AuthorizeAction::class;

    /**
     * @test
     */
    public function shouldImplementGenericTokenFactoryAwareInterface()
    {
        $rc = new \ReflectionClass($this->actionClass);

        $this->assertTrue($rc->implementsInterface(GenericTokenFactoryAwareInterface::class));
    }

    /**
     * @test
     */
    public function shouldRedirectToPaymentLink()
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

        $authorize = new Authorize($token);
        $authorize->setModel($details);

        $tokenFactory = $this->createMock(GenericTokenFactoryInterface::class);
        $tokenFactory
            ->expects($this->once())
            ->method('createNotifyToken')
            ->with('quickpay', $this->identicalTo($details))
            ->will($this->returnValue($token))
        ;

        $action = new AuthorizeAction();
        $action->setGateway($this->gateway);
        $action->setApi($this->api);
        $action->setGenericTokenFactory($tokenFactory);

        try {
            $action->execute($authorize);
        } catch (HttpRedirect $redirect) {
            $this->addToAssertionCount(1);
        }
    }
}
