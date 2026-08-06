<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Action\Api;

use Payum\Core\Exception\UnsupportedApiException;
use Setono\Payum\Quickpay\Api;

trait ApiAwareTrait
{
    protected Api $api;

    public function setApi($api): void
    {
        if (!$api instanceof Api) {
            throw new UnsupportedApiException(sprintf('Not supported api given. It must be an instance of %s', Api::class));
        }

        $this->api = $api;
    }
}
