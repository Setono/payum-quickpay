<?php

declare(strict_types=1);

namespace Setono\Payum\Quickpay\Tests\Bridge\PlainPhp\Action;

use Payum\Core\Request\GetHttpRequest;
use PHPUnit\Framework\TestCase;
use Setono\Payum\Quickpay\Bridge\PlainPhp\Action\HeaderAwareGetHttpRequestAction;
use Setono\Quickpay\Callback\CallbackValidator;
use stdClass;

/**
 * Under PHPUnit a getallheaders() polyfill (guzzle ships ralouphie/getallheaders) is loaded, so the
 * default source never reaches the `$_SERVER` reconstruction on its own. The tests therefore pin
 * three things separately: the action's sanitation over an injected source, the reconstruction
 * itself via {@see HeaderAwareGetHttpRequestAction::headersFromServer()}, and that the default
 * source — whichever provider it lands on — yields a header NotifyAction can find.
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
        // The $_SERVER reconstruction, injected explicitly so this passes the same way with or
        // without a getallheaders() polyfill on the include path.
        (new HeaderAwareGetHttpRequestAction(HeaderAwareGetHttpRequestAction::headersFromServer(...)))->execute($request);

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

        // The DEFAULT source — the production wiring — whichever provider it lands on here.
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
        (new HeaderAwareGetHttpRequestAction(HeaderAwareGetHttpRequestAction::headersFromServer(...)))->execute($request);

        /** @var array<string, string> $headers */
        $headers = $request->headers;

        self::assertArrayNotHasKey('Some-Var', $headers);
        self::assertArrayNotHasKey('SOME_VAR', $headers);
        self::assertArrayNotHasKey('Weird-Array', $headers);
    }

    /**
     * A polyfilled getallheaders() passes `$_SERVER` values through untouched, so the source can hand
     * back non-string names and non-scalar values. The action sanitizes whatever it is given the
     * same way, so the result is identical across providers.
     *
     * @test
     */
    public function shouldSanitizeWhateverTheSourceReturns(): void
    {
        $request = new GetHttpRequest();
        (new HeaderAwareGetHttpRequestAction(static fn (): array => [
            'QuickPay-Checksum-Sha256' => 'the-checksum',
            'X-Int' => 42,
            'X-Array' => ['not', 'scalar'],
            7 => 'numeric-name',
        ]))->execute($request);

        self::assertSame(['QuickPay-Checksum-Sha256' => 'the-checksum', 'X-Int' => '42'], $request->headers);
    }

    /**
     * When the reconstruction is what the default source falls back to (no getallheaders(), or one
     * that answers with an empty list), it reads `$_SERVER` — pinned here directly.
     *
     * @test
     */
    public function shouldReconstructHeadersFromServer(): void
    {
        $_SERVER['HTTP_QUICKPAY_CHECKSUM_SHA256'] = 'the-checksum';
        $_SERVER['HTTP_X_CUSTOM_HEADER'] = 'custom';
        $_SERVER['NOT_A_HEADER'] = 'ignored';

        $headers = HeaderAwareGetHttpRequestAction::headersFromServer();

        self::assertSame('the-checksum', $headers['Quickpay-Checksum-Sha256']);
        self::assertSame('custom', $headers['X-Custom-Header']);
        self::assertArrayNotHasKey('Not-A-Header', $headers);
    }
}
