<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Model;

use Psr\Http\Message\ResponseInterface;

class QuickPayPaymentLink extends QuickPayModel
{
    /** @var string */
    protected $url;

    public static function createFromResponse(ResponseInterface $response): self
    {
        $data = json_decode((string) $response->getBody(), false);

        return new self($data);
    }

    public function getUrl(): string
    {
        return $this->url;
    }
}
