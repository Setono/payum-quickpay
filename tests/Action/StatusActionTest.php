<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Tests\Action;

use Payum\Core\Action\ActionInterface;
use Setono\Payum\QuickPay\Action\StatusAction;
use Payum\Core\Request\GetHumanStatus;

class StatusActionTest extends ActionTestAbstract
{
    protected $requestClass = GetHumanStatus::class;

    protected $actionClass = StatusAction::class;

    /**
     * @test
     *
     * @throws \ReflectionException
     */
    public function shouldBeSubClassOfGatewayAwareAction(): void
    {
        $rc = new \ReflectionClass(StatusAction::class);
        $this->assertTrue($rc->implementsInterface(ActionInterface::class));
    }

    /**
     * @test
     */
    public function shouldMarkAsNew(): void
    {
        $statusRequest = new GetHumanStatus([]);

        $action = new StatusAction();
        $action->execute($statusRequest);
        $this->assertTrue($statusRequest->isNew(), 'Request should be marked as new');
    }
}
