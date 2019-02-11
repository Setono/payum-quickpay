<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Model;

abstract class QuickPayModel
{
    /**
     * @var \stdClass
     */
    protected $data;

    /**
     * @param \stdClass $data
     */
    protected function __construct(\stdClass $data)
    {
        $this->data = $data;
    }
}
