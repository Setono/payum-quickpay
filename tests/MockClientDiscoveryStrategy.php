<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Tests;

use Http\Discovery\Strategy\DiscoveryStrategy;
use Http\Mock\Client as MockHttpClient;

/**
 * A php-http/discovery strategy that hands out ONE shared mock PSR-18 client, so a test can let the
 * gateway factory build the SDK client the way it does in production — from the api key, via
 * discovery — and still see the requests that client sends.
 *
 * php-http's own MockClientStrategy instantiates a fresh mock per lookup, which the test could never
 * get hold of; a candidate given as a closure is returned as-is instead. Prepend it around the test
 * and restore the original strategies afterwards — the strategy list is global state.
 */
final class MockClientDiscoveryStrategy implements DiscoveryStrategy
{
    public static ?MockHttpClient $client = null;

    /**
     * @param string $type
     *
     * @return list<array{class: callable(): MockHttpClient}>
     */
    public static function getCandidates($type): array
    {
        if (null === self::$client || !is_a(MockHttpClient::class, $type, true)) {
            return [];
        }

        return [['class' => static fn (): MockHttpClient => self::$client ?? new MockHttpClient()]];
    }
}
