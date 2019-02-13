<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Model;

abstract class QuickPayModel
{
    /**
     * @var object
     */
    protected $data;

    /**
     * @param object $data
     */
    protected function __construct($data)
    {
        $this->data = $data;
    }
}
