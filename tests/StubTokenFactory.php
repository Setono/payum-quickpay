<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Tests;

use Payum\Core\Model\Token;
use Payum\Core\Security\GenericTokenFactoryInterface;
use Payum\Core\Security\TokenInterface;

/**
 * Test double for the token factory: every token it mints carries a fixed, recognisable target url
 * per type, and the notify tokens it created are recorded so a test can assert one was requested.
 *
 * Implements the (deprecated) GenericTokenFactoryInterface because that is what payum/core's
 * GenericTokenFactoryAwareInterface asks for; it is a test double, not a package dependency.
 */
final class StubTokenFactory implements GenericTokenFactoryInterface
{
    /** @var list<array{string, mixed}> gateway name + model of every notify token requested */
    public array $notifyTokensCreated = [];

    public function createToken($gatewayName, $model, $targetPath, array $targetParameters = [], $afterPath = null, array $afterParameters = []): TokenInterface
    {
        return $this->token($gatewayName, 'https://shop.example/' . (string) $targetPath, $afterPath);
    }

    public function createCaptureToken($gatewayName, $model, $afterPath, array $afterParameters = []): TokenInterface
    {
        return $this->token($gatewayName, 'https://shop.example/capture', $afterPath);
    }

    public function createAuthorizeToken($gatewayName, $model, $afterPath, array $afterParameters = []): TokenInterface
    {
        return $this->token($gatewayName, 'https://shop.example/authorize', $afterPath);
    }

    public function createRefundToken($gatewayName, $model, $afterPath = null, array $afterParameters = []): TokenInterface
    {
        return $this->token($gatewayName, 'https://shop.example/refund', $afterPath);
    }

    public function createPayoutToken($gatewayName, $model, $afterPath, array $afterParameters = []): TokenInterface
    {
        return $this->token($gatewayName, 'https://shop.example/payout', $afterPath);
    }

    public function createNotifyToken($gatewayName, $model = null): TokenInterface
    {
        $this->notifyTokensCreated[] = [(string) $gatewayName, $model];

        return $this->token($gatewayName, 'https://shop.example/notify?payum_token=stub-notify');
    }

    private function token(string $gatewayName, string $targetUrl, ?string $afterUrl = null): Token
    {
        $token = new Token();
        $token->setGatewayName($gatewayName);
        $token->setTargetUrl($targetUrl);
        $token->setAfterUrl($afterUrl);

        return $token;
    }
}
