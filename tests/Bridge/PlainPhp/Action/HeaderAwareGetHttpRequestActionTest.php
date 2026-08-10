<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Tests\Bridge\PlainPhp\Action;

use Payum\Core\Request\GetHttpRequest;
use PHPUnit\Framework\TestCase;
use Setono\Payum\Quickpay\Bridge\PlainPhp\Action\HeaderAwareGetHttpRequestAction;
use Setono\Quickpay\Callback\CallbackValidator;
use stdClass;

/**
 * Under PHPUnit, getallheaders() exists as guzzle's polyfill (ralouphie/getallheaders), which
 * rebuilds headers from `$_SERVER` much like the action's own fallback — so setting `HTTP_*` server
 * variables exercises the real production path either way, and the action's sanitation is what
 * makes the result identical across the two sources.
 */
final class HeaderAwareGetHttpRequestActionTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $originalServer;

    protected function setUp(): void
    {
        $this->originalServer = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
    }

    /**
     * @test
     */
    public function shouldSupportGetHttpRequestOnly(): void
    {
        $action = new HeaderAwareGetHttpRequestAction();

        self::assertTrue($action->supports(new GetHttpRequest()));
        self::assertFalse($action->supports(new stdClass()));
        self::assertFalse($action->supports('foo'));
    }

    /**
     * @test
     */
    public function shouldPopulateTheHeadersAlongsideTheParentFields(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_QUICKPAY_CHECKSUM_SHA256'] = 'the-checksum';
        $_SERVER['HTTP_USER_AGENT'] = 'the-agent';

        $request = new GetHttpRequest();
        (new HeaderAwareGetHttpRequestAction())->execute($request);

        // The parent's own population must be preserved…
        self::assertSame('POST', $request->method);

        // …and the headers — the property the plain-PHP parent never sets — must be there.
        /** @var array<string, string> $headers */
        $headers = $request->headers;

        self::assertSame('the-checksum', $headers['Quickpay-Checksum-Sha256']);
        self::assertSame('the-agent', $headers['User-Agent']);
    }

    /**
     * The reconstruction produces `Quickpay-Checksum-Sha256` while Quickpay sends
     * `QuickPay-Checksum-Sha256` (capital P). NotifyAction matches the header name
     * case-insensitively, and this pins that the two really do meet.
     *
     * @test
     */
    public function shouldProduceAHeaderNameNotifyActionMatches(): void
    {
        $_SERVER['HTTP_QUICKPAY_CHECKSUM_SHA256'] = 'the-checksum';

        $request = new GetHttpRequest();
        (new HeaderAwareGetHttpRequestAction())->execute($request);

        /** @var array<string, string> $headers */
        $headers = $request->headers;

        $found = false;
        foreach (array_keys($headers) as $name) {
            if (0 === strcasecmp($name, CallbackValidator::CHECKSUM_HEADER)) {
                $found = true;
            }
        }

        self::assertTrue($found, 'The checksum header must be findable case-insensitively');
    }

    /**
     * @test
     */
    public function shouldSkipNonHeaderAndNonScalarServerEntries(): void
    {
        $_SERVER['SOME_VAR'] = 'not-a-header';
        $_SERVER['HTTP_WEIRD_ARRAY'] = ['not', 'scalar'];

        $request = new GetHttpRequest();
        (new HeaderAwareGetHttpRequestAction())->execute($request);

        /** @var array<string, string> $headers */
        $headers = $request->headers;

        self::assertArrayNotHasKey('Some-Var', $headers);
        self::assertArrayNotHasKey('SOME_VAR', $headers);
        self::assertArrayNotHasKey('Weird-Array', $headers);
    }
}
