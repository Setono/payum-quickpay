<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Action\Api;

use Payum\Core\Exception\UnsupportedApiException;
use function Safe\sprintf;
use Setono\Payum\QuickPay\Api;

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
