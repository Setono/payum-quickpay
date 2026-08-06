# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

`setono/payum-quickpay` is a [Payum](https://github.com/Payum/Payum) gateway that integrates the
[Quickpay](https://quickpay.net) payment provider (a Danish PSP). It is a library — there is no
runnable application — consumed by projects that wire it into Payum (commonly Sylius shops). The
package targets **PHP >= 8.1** and the Quickpay API **v10**.

## Commands

All commands run through Composer scripts (see the `scripts` block in `composer.json`). The repo-local
binaries live in `vendor/bin`, and the user's shell aliases (`ca`, `cf`, etc.) map to these.

- `composer test` / `composer phpunit` — run the PHPUnit suite
- `vendor/bin/phpunit --filter testMethodName` — run a single test method
- `vendor/bin/phpunit tests/Action/CaptureActionTest.php` — run a single test file
- `composer analyse` — PHPStan static analysis (`phpstan.neon.dist`: level 8, `src` only — matching the
  scope the old Psalm config used; the Payum `ArrayObject` request pattern produces unavoidable `mixed`
  at level 9/max)
- `composer check-style` — ECS (Easy Coding Standard) dry-run check
- `composer fix-style` — ECS auto-fix
- `composer rector` — Rector dry/apply (config in `rector.php`; not run in CI)
- `composer infection` — Infection mutation testing (`infection.json.dist`: source `src`, gates
  `minMsi 65` / `minCoveredMsi 70`; needs a coverage driver — CI runs it on PHP 8.3 with pcov)
- `composer checks` — style check + static analysis
- `composer all` — `checks` + `test` (the full local gate)
- `composer normalize` — normalize `composer.json` (CI enforces `--dry-run`)
- `vendor/bin/composer-dependency-analyser` — shipmonk dependency hygiene (shadow/unused deps); config
  in `composer-dependency-analyser.php` ignores the `php-http/message-factory` unused-dependency error
  (it is a runtime dependency of `payum/core`'s gateway wiring, not referenced by our code)

The dev toolchain is inlined directly into `require-dev` (PHPStan + extensions, PHPUnit, Rector,
Infection, ECS via `sylius-labs/coding-standard`, shipmonk, `php-http/mock-client`) rather than pulled
via a meta-package. CI (`.github/workflows/build.yaml`) runs the matrix on **PHP 8.1–8.5** — `unit-tests`
on both `lowest` and `highest` Composer resolutions, everything else on `highest` — plus dedicated
`code-coverage` (Codecov) and `mutation-tests` (Infection) jobs on PHP 8.3.

## Architecture

This package follows Payum's request/action gateway pattern. A consumer creates a gateway via
`QuickpayGatewayFactory`, then `execute()`s Payum request objects against it. Each request is routed
to a matching Action.

### The SDK

All HTTP and (de)serialization is delegated to [`setono/quickpay-php-sdk`](https://github.com/Setono/quickpay-php-sdk)
(namespace `Setono\Quickpay\`) — a PSR-18/PSR-17 client (auto-discovered via `php-http/discovery`) with
typed `payments()` endpoints, request/response DTOs, non-exhaustive `PaymentState`/`OperationType` enums,
a `QuickpayException` hierarchy, and a timing-safe `CallbackValidator`. Basic auth, the mandatory
`Accept-Version: v10` header and host pinning to `api.quickpay.net` all live in the SDK. The SDK is
currently pinned at `^1.0.0-alpha.3`. The SDK is pre-1.0 and every alpha has tightened its contract, so
the gateway tracks the newest alpha rather than supporting a matrix of them — and the explicit minimum
keeps the `--prefer-lowest` CI job on the same release. alpha.2 added the client-wide `synchronized`
default the gateway relies on (alpha.1 genuinely cannot run it); alpha.3 turned the unconditionally
required request fields into required constructor params (`CreatePaymentRequest::$orderId`/`$currency`,
`$amount` on `CreateLinkRequest`/`CaptureRequest`/`RefundRequest`) — every call site here already passes
them, so a missing one is now a `TypeError` at the call site instead of a `ValidationException` after a
round trip.

### Wiring

`QuickpayGatewayFactory::populateConfig()` is the composition root. It registers every action under a
`payum.action.*` key and defines `payum.api` — a factory closure that builds the `Api` value object.
Required options are just `apikey` and `privatekey`; other options (`payment_methods`, `auto_capture`,
`order_prefix`, `language`, `synchronized`, `agreement` → link `agreementId`, `branding_id`) are
defaulted. The closure constructs the SDK `Client` from the api key **and the `synchronized` flag** (or
accepts a prebuilt `Setono\Quickpay\Client\ClientInterface` via the optional `quickpay.client` option —
used by tests). Because `synchronized` is a constructor-only default on the SDK client, an injected
client whose `isSynchronized()` disagrees with the gateway option is rejected with a `LogicException`
rather than silently overriding it.

### Actions (`src/Action/`)

Actions are the unit of behavior. Each implements `ActionInterface` plus the aware-interfaces it needs
(`ApiAwareInterface` via `Action/Api/ApiAwareTrait`, `GatewayAwareInterface`,
`GenericTokenFactoryAwareInterface`). `supports()` gates on the Payum request type **and** the model
being an `ArrayAccess`. The model is normalized with `ArrayObject::ensureArrayObject($request->getModel())`;
the `quickpayPaymentId` (int) it carries is the single source of truth — actions re-fetch the payment via
`Api::payments()->getById()` rather than passing the model through as API params.

Request → Action flow (amounts are integer minor units everywhere — no conversion):
- **Convert** → `ConvertPaymentAction` — turns a Payum `PaymentInterface` into the details array; creates
  the Quickpay payment (via `CreatePaymentRequest`) if absent and stores **only scalars** —
  `quickpayPaymentId`, `amount`, `currency`, `order_id` — plus `continue_url`/`cancel_url` from the
  token's after-URL. It never persists DTO/model objects.
- **Authorize** → `AuthorizeAction` — builds a notify (callback) token into `callback_url`, creates a
  payment link (`createLink` + `CreateLinkRequest`), and **throws `HttpRedirect`** to Quickpay's hosted
  payment window.
- **Capture / Refund / Cancel** → call `Api::payments()->capture/refund/cancel(...)`. Operations are
  asynchronous by default (final state arrives via the callback); the actions pass no per-call
  `synchronized` argument — the SDK client's client-wide default (from the `synchronized` option, default
  off) is the single toggle that flips them to synchronous (`?synchronized`). The `Payment` these calls
  return is deliberately ignored — on the async path it is a snapshot taken when the operation was
  queued (`pending: true`, pre-operation `state`/`balance`), so `StatusAction` re-fetches instead of
  trusting it. `CancelAction`
  catches the typed `QuickpayException` and swallows the "Transaction in wrong state for this operation"
  case (so cancel is idempotent), rethrowing anything else.
- **Notify** → `NotifyAction` — entry point for Quickpay's server-to-server callback. It fetches the raw
  body + `QuickPay-Checksum-Sha256` header (via Payum's `GetHttpRequest`), **verifies the HMAC signature**
  with the SDK `CallbackValidator`, and rejects an invalid/unsigned callback with a 400 `HttpResponse`
  before delegating to the internal `ConfirmPayment` request.
- **ConfirmPayment** (internal, `src/Request/Api/` + `Action/Api/ConfirmPaymentAction`) — when
  `auto_capture` is on and the latest operation is an approved authorize whose amount matches, it captures
  automatically.
- **GetStatus** → `StatusAction` — maps the SDK `PaymentState` + latest operation to Payum marks
  (`markCaptured`, `markRefunded`, `markAuthorized`, `markFailed`, etc.).

### Api (`src/Api.php`)

A `final`, immutable value object injected as `payum.api`. It performs **no HTTP itself** — it wraps the
configured SDK `ClientInterface` (exposed via `getClient()` / `payments()`) plus the behavior options the
actions need (`getOrderPrefix()`, `getPaymentMethods()`, `getLanguage()`, `isAutoCapture()`,
`getAgreementId()`, `getBrandingId()`), and exposes `createCallbackValidator()` for notify verification.
`isSynchronized()` is not a state of its own — it reads through to the wrapped client, so there is one
source of truth for the flag.

### Operations helper (`src/Operations.php`)

The custom `src/Model/*` classes are gone — responses are the SDK's readonly DTOs (`Response\Payment\
{Payment,Operation,Link}`) plus the `PaymentState`/`OperationType` enums. The behavior that used to live
on those models now lives in the stateless `Operations` helper over a `list<Operation>`: `latest()`,
`isApproved()` (status code `20000`), `isApprovedOfType()`, `isLatestApproved()`, `authorizedAmount()`.
`StatusAction` and `ConfirmPaymentAction` decisions are driven by these helpers plus the SDK enums.

## Testing

Tests mirror `src/` under `tests/` and are **fully offline and deterministic** — no network, no shared
Quickpay account. The HTTP seam is a PSR-18 `Http\Mock\Client` (`php-http/mock-client`) injected into the
SDK `Client`; `tests/ApiTestTrait` builds the `Api` around it with fake credentials and provides
`queueResponse()` / `queuePayment()` (FIFO response queue), `operation()`, fixture builders, and
request-shape assertion helpers (`assertRequest()` checks method, path, Basic auth and `Accept-Version`).
Tests assert both the resulting Payum marks/replies **and** that the correct Quickpay requests were sent
(`getRequests()`).

Most action tests extend `ActionTestAbstract` → `GenericActionTestCase`, a **local copy** of Payum's
`GenericActionTest` (Payum ships it `export-ignore`, so it is absent on `--prefer-dist`/CI installs); it
uses Prophecy for gateway/token doubles. `ConvertPaymentActionTest` stands alone because Payum's `Convert`
is not a `Generic` request. `NotifyAction`'s callback verification is tested via
`tests/StubGetHttpRequestAction` (feeds a raw body + checksum header into `GetHttpRequest`).

Two important Valinor gotchas when writing fixtures: the SDK mapper is strict, so a payment JSON must
include every typed non-optional field (`id`, `order_id`, `currency`, `state`, `merchant_id`) and a single
mistyped nested field fails the **whole** mapping; queue the response with `queuePayment()`/`paymentJson()`
which already supply these.

PHPStan intentionally analyzes `src` only — the loosely-typed Payum test base classes and Prophecy produce
unavoidable noise in `tests/`, so test-only type regressions are caught by PHPUnit (and mutation testing),
not PHPStan.
