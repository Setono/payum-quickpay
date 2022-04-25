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
        $body = (string) $response->getBody();

        try {
            $data = json_decode($body, false, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new JsonException(sprintf(
                'Could not json_decode input. Error was: %s. Called in: %s. Input was: %s',
                $e->getMessage(),
                __METHOD__,
                $body === '' ? 'Empty' : $body
            ), $e->getCode(), $e);
        }

        return new self($data);
    }

    public function getUrl(): string
    {
        return $this->url;
    }
}
