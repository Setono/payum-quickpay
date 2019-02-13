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
        foreach (get_object_vars($data) as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
    }
}
