<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Action\Api;

use Setono\Payum\QuickPay\Api;
use Payum\Core\Exception\UnsupportedApiException;

trait ApiAwareTrait
{
    /**
     * @var Api
     */
    protected $api;

    /**
     * @param Api $api
     */
    public function setApi($api): void
    {
        if (!$api instanceof Api) {
            throw new UnsupportedApiException(sprintf('Not supported api given. It must be an instance of %s', Api::class));
        }

        $this->api = $api;
    }
}
