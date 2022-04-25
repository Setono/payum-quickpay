<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Model;

use JsonException;
use Psr\Http\Message\ResponseInterface;

class QuickPayPaymentLink extends QuickPayModel
{
    protected string $url;

    public static function createFromResponse(ResponseInterface $response): self
    {
        try {
            $data = json_decode((string) $response->getBody(), false, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new JsonException(sprintf('[%s] %s', __METHOD__, $e->getMessage()), $e->getCode(), $e);
        }

        return new self($data);
    }

    public function getUrl(): string
    {
        return $this->url;
    }
}
