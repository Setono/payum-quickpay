<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Model;

abstract class QuickPayModel
{
    protected function __construct(object $data)
    {
        foreach (get_object_vars($data) as $key => $value) {
            if (property_exists($this, $key)) {
                (new \ReflectionProperty($this, $key))->setValue($this, $value);
            }
        }
    }
}
