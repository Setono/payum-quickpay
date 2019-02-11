<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Tests\Action;

use Payum\Core\Request\Capture;
use Payum\Core\Security\GenericTokenFactoryAwareInterface;
use Setono\Payum\QuickPay\Action\CaptureAction;

class CaptureActionTest extends ActionTestAbstract
{
    protected $requestClass = Capture::class;

    protected $actionClass = CaptureAction::class;

    /**
     * @test
     */
    public function shouldImplementGenericTokenFactoryAwareInterface()
    {
        $rc = new \ReflectionClass($this->actionClass);

        $this->assertTrue($rc->implementsInterface(GenericTokenFactoryAwareInterface::class));
    }
}
