<?php

namespace Setono\Payum\QuickPay;

use Setono\Payum\QuickPay\Model\QuickPayPayment;
use Setono\Payum\QuickPay\Model\QuickPayPaymentLink;
use Payum\Core\Exception\InvalidArgumentException;
use Payum\Core\Exception\LogicException;
use Psr\Http\Message\ResponseInterface;
use Http\Message\MessageFactory;
use Payum\Core\Exception\Http\HttpException;
use Payum\Core\HttpClientInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Model\Payment;

class Api
{
    const VERSION = 'v10';

    /**
     * @var HttpClientInterface
     */
    protected $client;

    /**
     * @var MessageFactory
     */
    protected $messageFactory;

    /**
     * @var array
     */
    protected $options = [];

    /**
     * @param array               $options
     * @param HttpClientInterface $client
     * @param MessageFactory      $messageFactory
     *
     * @throws InvalidArgumentException if an option is invalid
     */
    public function __construct(array $options, HttpClientInterface $client, MessageFactory $messageFactory)
    {
        $this->options = $options;
        $this->client = $client;
        $this->messageFactory = $messageFactory;
    }

    /**
     * @param ArrayObject $params
     * @param bool        $create
     *
     * @return QuickPayPayment
     */
    public function getPayment(ArrayObject $params, bool $create = true): QuickPayPayment
    {
        $params = ArrayObject::ensureArrayObject($params);

        if (isset($params['quickpayPayment']) && $params['quickpayPayment'] instanceof QuickPayPayment) {
            return $params['quickpayPayment'];
        }

        if (is_integer($params['quickpayPaymentId'])) {
            $response = $this->doRequest('GET', 'payments/' . $params['quickpayPaymentId']);
        } else {
            /** @var Payment $paymentModel */
            $paymentModel = $params['payment'];
            if ($create) {
                $response = $this->doRequest('POST', 'payments', [
                    'order_id' => $this->getOption('order_prefix', '') . $paymentModel->getNumber(),
                    'currency' => $paymentModel->getCurrencyCode(),
                ]);
            } else {
                throw new LogicException("Payment with number {$paymentModel->getNumber()} does not exist");
            }
        }

        return QuickPayPayment::createFromResponse($response);
    }

    /**
     * @param QuickPayPayment $payment
     * @param ArrayObject     $params
     *
     * @return QuickPayPaymentLink
     */
    public function createPaymentLink(QuickPayPayment $payment, ArrayObject $params)
    {
        $params = ArrayObject::ensureArrayObject($params);
        $params->validateNotEmpty([
            'continue_url', 'cancel_url', 'callback_url', 'amount'
        ]);

        $response = $this->doRequest('PUT', 'payments/' . $payment->getId() . '/link', $params->getArrayCopy() + [
            'payment_methods' => $this->options['payment_methods'],
            'auto_capture' => $this->options['auto_capture']
        ]);

        return QuickPayPaymentLink::createFromResponse($response);
    }

    /**
     * @param QuickPayPayment $payment
     * @param ArrayObject     $params
     *
     * @thorws LogicException
     */
    public function authorizePayment(QuickPayPayment $payment, ArrayObject $params)
    {
        throw new \LogicException('Not implemented, use payment link.');
    }

    /**
     * @param QuickPayPayment $payment
     * @param ArrayObject     $params
     *
     * @return QuickPayPayment
     */
    public function capturePayment(QuickPayPayment $payment, ArrayObject $params)
    {
        $params = ArrayObject::ensureArrayObject($params);
        $params->validateNotEmpty([
            'amount'
        ]);

        $response = $this->doRequest('POST', 'payments/' . $payment->getId() . '/capture', $params->getArrayCopy());

        return QuickPayPayment::createFromResponse($response);
    }

    /**
     * @param QuickPayPayment $payment
     * @param ArrayObject     $params
     *
     * @return QuickPayPayment
     */
    public function refundPayment(QuickPayPayment $payment, ArrayObject $params)
    {
        $params = ArrayObject::ensureArrayObject($params);
        $params->validateNotEmpty([
            'amount'
        ]);

        $response = $this->doRequest('POST', 'payments/' . $payment->getId() . '/refund', $params->getArrayCopy());

        return QuickPayPayment::createFromResponse($response);
    }

    /**
     * @param QuickPayPayment $payment
     * @param ArrayObject     $params
     *
     * @return QuickPayPayment
     */
    public function cancelPayment(QuickPayPayment $payment, ArrayObject $params)
    {
        $params = ArrayObject::ensureArrayObject($params);
        $params->validateNotEmpty([
            'amount'
        ]);

        $response = $this->doRequest('POST', 'payments/' . $payment->getId() . '/cancel', $params->getArrayCopy());

        return QuickPayPayment::createFromResponse($response);
    }


    /**
     * @param string $method
     * @param string $path
     * @param array  $params
     *
     * @return ResponseInterface
     */
    protected function doRequest($method, string $path, array $params = [])
    {
        $headers = [
            'Authorization' => 'Basic ' . base64_encode(":" . $this->getOption('apikey')),
            'Accept-Version' => self::VERSION,
            'Content-Type' => 'application/json',
        ];

        $request = $this->messageFactory->createRequest(
            $method,
            $this->getApiEndpoint() . '/' . ltrim($path, '/'),
            $headers,
            json_encode($params)
        );

        $response = $this->client->send($request);

        if (false == ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300)) {
            throw new HttpException($response->getBody(), $response->getStatusCode());
        }

        self::assertValidResponse($response, $this->getOption('privatekey'));

        return $response;
    }

    /**
     * @return string
     */
    protected function getApiEndpoint()
    {
        return 'https://api.quickpay.net';
    }

    /**
     * Generates a checksum based on response body
     *
     * @param string $requestBody
     * @param string $privateKey
     *
     * @return string
     */
    public static function checksum($requestBody, $privateKey)
    {
        return hash_hmac("sha256", $requestBody, $privateKey);
    }

    /**
     * @param ResponseInterface $response
     * @param string            $privateKey
     */
    public static function assertValidResponse(ResponseInterface $response, string $privateKey)
    {
        if ($response->hasHeader("QuickPay-Checksum-Sha256")) {
            $checksum = self::checksum($response->getBody(), $privateKey);
            $qp_checksum = $response->getHeader("QuickPay-Checksum-Sha256");
            if ($checksum != $qp_checksum) {
                throw new \LogicException("Invalid checksum");
            }
        }
    }

    /**
     * @param string $option
     *
     * @param mixed $default
     *
     * @return mixed|null
     */
    public function getOption(string $option, $default = '')
    {
        return isset($this->options[$option]) ? $this->options[$option] : $default;
    }
}
