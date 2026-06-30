# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

`setono/payum-quickpay` is a [Payum](https://github.com/Payum/Payum) gateway that integrates the
[QuickPay](https://quickpay.net) payment provider (a Danish PSP). It is a library — there is no
runnable application — consumed by projects that wire it into Payum (commonly Sylius shops). The
package targets **PHP >= 8.1** and the QuickPay API **v10**.

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
- `composer checks` — style check + static analysis
- `composer all` — `checks` + `test` (the full local gate)
- `composer normalize` — normalize `composer.json` (CI enforces `--dry-run`)
- `vendor/bin/composer-dependency-analyser` — shipmonk dependency hygiene (shadow/unused deps)

The dev toolchain is inlined directly into `require-dev` (PHPStan + extensions, PHPUnit, Rector,
Infection, ECS via `sylius-labs/coding-standard`, shipmonk) rather than pulled via a meta-package.
CI (`.github/workflows/build.yaml`) runs the matrix on **PHP 8.1–8.4**, both `lowest` and `highest`
Composer dependency resolutions.

## Architecture

This package follows Payum's request/action gateway pattern. A consumer creates a gateway via
`QuickPayGatewayFactory`, then `execute()`s Payum request objects against it. Each request is routed
to a matching Action.

### Wiring

`QuickPayGatewayFactory::populateConfig()` is the composition root. It registers every action under a
`payum.action.*` key and defines `payum.api` — a factory closure that builds the `Api` service from the
gateway's options after validating required options (`apikey`, `merchant`, `agreement`, `privatekey`,
`language`). Options also include behavior flags such as `auto_capture`, `order_prefix`, and
`payment_methods`.

### Actions (`src/Action/`)

Actions are the unit of behavior. Each implements `ActionInterface` plus the aware-interfaces it needs
(`ApiAwareInterface` via `Action/Api/ApiAwareTrait`, `GatewayAwareInterface`,
`GenericTokenFactoryAwareInterface`). `supports()` gates on the Payum request type **and** the model
being an `ArrayAccess`. The model is always normalized with `ArrayObject::ensureArrayObject($request->getModel())`,
and that same `ArrayObject` is passed straight through to `Api` methods as the request params (so model
keys like `amount`, `quickpayPaymentId`, `card`, `continue_url` double as API parameters).

Request → Action flow:
- **Convert** → `ConvertPaymentAction` — turns a Payum `PaymentInterface` into the details array;
  creates the QuickPay payment if absent and stores `quickpayPayment` / `quickpayPaymentId`; sets
  `continue_url`/`cancel_url` from the token's after-URL.
- **Authorize** → `AuthorizeAction` — builds a notify (callback) token, creates a QuickPay payment link,
  and **throws `HttpRedirect`** to send the user to QuickPay's hosted payment window.
- **Capture / Refund / Cancel** → corresponding actions call `Api::getPayment()` then the matching
  `Api::*Payment()` method. `CancelAction` swallows the "cannot be cancelled" case instead of throwing.
- **Notify** → `NotifyAction` — entry point for QuickPay's server-to-server callback; delegates to the
  internal `ConfirmPayment` request.
- **ConfirmPayment** (internal, `src/Request/Api/` + `Action/Api/ConfirmPaymentAction`) — when
  `auto_capture` is on and the latest operation is an approved authorize whose amount matches, it
  captures automatically.
- **GetStatus** → `StatusAction` — maps QuickPay payment `state` + latest operation to Payum marks
  (`markCaptured`, `markRefunded`, `markAuthorized`, `markFailed`, etc.).

### Api (`src/Api.php`)

The single HTTP-facing class. Wraps a Payum `HttpClientInterface` + PSR-7 `MessageFactory`. Every call
goes through `doRequest()`, which sets Basic auth from `apikey`, the `Accept-Version: v10` header,
JSON-encodes the body, and throws `HttpException` on non-2xx. Responses carrying a
`QuickPay-Checksum-Sha256` header are HMAC-SHA256 verified against `privatekey` (`assertValidResponse` /
`validateChecksum`). The endpoint is hard-coded to `https://api.quickpay.net`.

### Models (`src/Model/`)

Plain data objects hydrated from QuickPay JSON responses. `QuickPayModel` is the base; subclasses expose
typed getters and `createFromResponse()` / `createFromObject()` factories. State logic lives here:
`QuickPayPayment` exposes `STATE_*` constants and `getLatestOperation()` / `getAuthorizedAmount()`;
`QuickPayPaymentOperation` exposes `TYPE_*` constants and `isApproved()` (status code `20000`). The
`StatusAction` and `ConfirmPaymentAction` decisions are driven entirely by these constants — change them
in lockstep with QuickPay's API semantics.

## Testing

Tests mirror `src/` under `tests/`. Action tests extend `ActionTestAbstract` (which extends Payum's
`GenericActionTest`) and assert the action implements the expected interfaces in addition to behavior.

**The suite is integration-style, not mocked:** `ApiTestTrait::createGatewayMock()` builds a real
gateway with hard-coded QuickPay test credentials, and the action/API tests make **live HTTP calls** to
`https://api.quickpay.net`. This means the suite needs network access and is inherently flaky — several
tests (`CaptureActionTest`, `StatusActionTest`, `RefundActionTest`) depend on QuickPay's asynchronous
operation processing and shared test-account state, so individual runs occasionally fail on timing and
pass on re-run. `tests/bootstrap.php` also adds Payum core's own `tests/` dir to the autoloader so
`GenericActionTest` resolves. PHPStan intentionally does not analyze `tests/` (the loosely-typed Payum
base class makes a clean pass impractical), so test-only type regressions are caught by PHPUnit, not
PHPStan.
