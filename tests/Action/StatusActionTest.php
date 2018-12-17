<?php

namespace Setono\Payum\QuickPay\Tests\Action;

use Setono\Payum\QuickPay\Action\StatusAction;
use Payum\Core\Request\GetHumanStatus;

class StatusActionTest extends ActionTestAbstract
{
    protected $requestClass = GetHumanStatus::class;

    protected $actionClass = StatusAction::class;

    /**
     * @test
     */
    public function shouldBeSubClassOfGatewayAwareAction()
    {
        $rc = new \ReflectionClass(StatusAction::class);
        $this->assertTrue($rc->implementsInterface('Payum\Core\Action\ActionInterface'));
    }

    /**
     * @test
     */
    public function shouldMarkAsNew()
    {
        $statusRequest = new GetHumanStatus([]);

        $action = new StatusAction();
        $action->execute($statusRequest);
        $this->assertTrue($statusRequest->isNew(), 'Request should be marked as new');
    }
}
