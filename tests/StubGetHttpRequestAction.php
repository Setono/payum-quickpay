<?php

declare(strict_types=1);

namespace Setono\Payum\QuickPay\Tests;

use Payum\Core\Action\ActionInterface;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Request\GetHttpRequest;

/**
 * Test double that populates a {@see GetHttpRequest} with a predetermined raw body and headers,
 * standing in for Payum's real GetHttpRequestAction (which reads PHP superglobals / the Symfony
 * request). The `headers` property mirrors what the Symfony bridge sets.
 */
final class StubGetHttpRequestAction implements ActionInterface
{
    /**
     * @param array<string, mixed> $headers
     */
    public function __construct(
        private string $content = '',
        private array $headers = [],
    ) {
    }

    /**
     * @param array<string, mixed> $headers
     */
    public function setHttpRequest(string $content, array $headers): void
    {
        $this->content = $content;
        $this->headers = $headers;
    }

    /**
     * @param mixed|GetHttpRequest $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $request->content = $this->content;
        $request->headers = $this->headers;
    }

    public function supports($request): bool
    {
        return $request instanceof GetHttpRequest;
    }
}
