<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Tests;

use Payum\Core\HttpClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Test double for {@see HttpClientInterface}. It returns pre-queued responses in FIFO order so the
 * suite never touches the live QuickPay API. Queue the responses a test expects, in the order the
 * code under test will perform the requests.
 */
final class StubHttpClient implements HttpClientInterface
{
    /** @var list<ResponseInterface> */
    private array $responses = [];

    /** @var list<RequestInterface> */
    private array $requests = [];

    public function addResponse(ResponseInterface $response): void
    {
        $this->responses[] = $response;
    }

    /**
     * @return ResponseInterface
     */
    public function send(RequestInterface $request)
    {
        $this->requests[] = $request;

        $response = array_shift($this->responses);
        if (null === $response) {
            throw new RuntimeException(sprintf(
                'No stubbed response queued for request "%s %s".',
                $request->getMethod(),
                (string) $request->getUri(),
            ));
        }

        return $response;
    }

    /**
     * @return list<RequestInterface>
     */
    public function getRequests(): array
    {
        return $this->requests;
    }
}
