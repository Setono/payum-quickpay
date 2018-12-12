<?php

namespace Setono\Payum\QuickPay\Model;

use Psr\Http\Message\ResponseInterface;

/**
 * @author jdk
 */
class QuickPayPaymentLink extends QuickPayModel
{
    /**
     * @param ResponseInterface $response
     *
     * @return self
     */
    public static function createFromResponse(ResponseInterface $response)
    {
        $data = json_decode($response->getBody());

        return new self($data);
    }

    /**
     * @return string
     */
    public function getUrl()
    {
        return $this->data->url;
    }
}
