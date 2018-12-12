<?php

namespace Setono\Payum\QuickPay\Tests\Action;

use Setono\Payum\QuickPay\Action\Api\ApiAwareTrait;
use Setono\Payum\QuickPay\Action\StatusAction;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\GetHumanStatus;
use Payum\Core\Tests\Action\GatewayAwareActionTest;

class StatusActionTest extends GatewayAwareActionTest
{
    use GatewayAwareTrait;
    use ApiAwareTrait;

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
        $request = new GetHumanStatus([
            'status' => 'NEW'
        ]);
        $action = new StatusAction();
        $action->execute($request);
        $this->assertTrue($request->isNew(), 'Request should be marked as new');
    }
}
