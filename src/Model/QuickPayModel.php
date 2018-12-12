<?php

namespace Setono\Payum\QuickPay\Model;

/**
 * @author jdk
 */
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
