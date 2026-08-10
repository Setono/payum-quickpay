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
  `minMsi 65` / `minCoveredMsi 70`; needs a coverage driver — CI runs it on PHP 8.3 with pcov). The
  Stryker dashboard upload behind the README badge is **branch-gated** in `infection.json.dist`
  (`logs.stryker.badge`, currently `2.x`) and needs the `STRYKER_DASHBOARD_API_KEY` repository secret,
  which the CI job passes through — pull request runs compute the score but publish nothing. Update the
  gated branch when the working branch changes, or the badge silently stops refreshing.
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
currently constrained to `^1.0@beta`, resolving to `1.0.0-beta.2` (and to `beta.1` under
`--prefer-lowest`, which both CI and this package are compatible with — beta.2 only removed
`CollectionRequestOptions::new()` and the `webmozart/assert` dependency, neither of which this package
uses). The beta declares the API surface
stable for 1.0 with no further BC breaks planned, so the constraint no longer has to chase individual
releases the way it did through the alphas — `@beta` also excludes the alphas outright, which matters
because the gateway cannot run on alpha.1 (no client-wide `synchronized`) and would break on alpha.1–2
(request fields only became required constructor params in alpha.3).

Consumers therefore need `"minimum-stability": "beta"` with `"prefer-stable": true` until 1.0.0 is
tagged; the README and `docs/UPGRADE-2.0.md` say so.

### Wiring

`QuickpayGatewayFactory::populateConfig()` is the composition root. It registers every action under a
`payum.action.*` key and defines `payum.api` — a factory closure that builds the `Api` value object.
Required options are just `api_key` and `private_key`. Three 1.x names — `apikey`, `privatekey` and
`agreement` — remain as deprecated aliases, mapped by `aliasDeprecatedOptions()` **before** the defaults
are applied; after that, `api_key` exists as `''` and there is no way to tell the consumer only supplied
the old name. They are aliased rather than dropped (unlike the misspelled `syncronized`, which nobody had
meaningfully set) because Sylius stores the gateway config keyed by these names, so a hard rename would
break every existing shop. `agreement` is the one to be most careful with: it is optional, so a name that
stops being read fails **silently** — the link is created without an agreement id and Quickpay falls back
to the account default. Other options (`payment_methods`, `auto_capture`,
`order_prefix`, `language`, `synchronized`, `agreement_id` → link `agreementId`, `branding_id`) are
defaulted. The closure constructs the SDK `Client` from the api key **and the `synchronized` flag** (or
accepts a prebuilt `Setono\Quickpay\Client\ClientInterface` via the optional `quickpay.client` option —
used by tests). Because `synchronized` is a constructor-only default on the SDK client, an injected
client whose `isSynchronized()` disagrees with the gateway option is rejected with a `LogicException`
rather than silently overriding it. `payment_methods` accepts a string **or** a list of strings and is
normalized to Quickpay's comma-separated form by `normalizePaymentMethods()` — a `(string)` cast of a
list would have sent the literal `Array`, which reads as an allowlist of one unknown method and rejects
every payment; anything that is neither shape throws rather than being coerced. Empty configuration
normalizes to `null` — as `agreement_id` and `branding_id` already did — so the empty-string Payum default
never reaches `Api`, and `null` is what keeps the parameter off the request entirely.

### Actions (`src/Action/`)

Actions are the unit of behavior. Each implements `ActionInterface` plus the aware-interfaces it needs —
`ApiAwareInterface` (via `Action/Api/ApiAwareTrait`, which types `$api` as `Api` so PHPStan can see
through it, unlike payum/core's `mixed` version) and `GatewayAwareInterface`. Only `AuthorizeAction`
implements `GenericTokenFactoryAwareInterface`, because only it mints a token (the notify token for
`callback_url`); keep it that way, since that interface is the package's one remaining contact with
payum/core's deprecated `GenericTokenFactoryInterface` (issue #3). `supports()` gates on the Payum request type **and** the model
being an `ArrayAccess`. The model is normalized with `ArrayObject::ensureArrayObject($request->getModel())`;
the `quickpayPaymentId` (int) it carries is the single source of truth — actions re-fetch the payment via
`Api::payments()->getById()` rather than passing the model through as API params.

Request → Action flow (amounts are integer minor units everywhere — no conversion):
- **Convert** → `ConvertPaymentAction` — turns a Payum `PaymentInterface` into the details array; creates
  the Quickpay payment (via `CreatePaymentRequest`) if absent and stores **only scalars** —
  `quickpayPaymentId`, `amount`, `currency`, `order_id` — plus `continue_url`/`cancel_url` from the
  token's after-URL. It never persists DTO/model objects. On the create path only it asserts its inputs:
  Payum types `getNumber()`/`getCurrencyCode()` as `@return string` but both model properties are
  nullable, so `assertNotEmptyString()` takes `mixed` (which also stops PHPStan folding the checks away
  as always-true). A missing currency would be a `TypeError` from inside the SDK DTO; a missing number
  is worse, degrading silently to an order id that is nothing but the prefix. `assertOrderId()` then
  enforces Quickpay's **4–20 character** `order_id` rule on `order_prefix . number` — which also catches
  that silent case, except when the prefix alone is 4+ characters, where it would otherwise create every
  payment under the same order id.
- **Authorize** → `AuthorizeAction` — builds a notify (callback) token into `callback_url`, creates a
  payment link (`createLink` + `CreateLinkRequest`), and **throws `HttpRedirect`** to Quickpay's hosted
  payment window.
- **Capture / Refund / Cancel** → call `Api::payments()->capture/refund/cancel(...)`. Operations are
  asynchronous by default (final state arrives via the callback); the actions pass no per-call
  `synchronized` argument — the SDK client's client-wide default (from the `synchronized` option, default
  off) is the single toggle that flips them to synchronous (`?synchronized`). The `Payment` these calls
  return is deliberately ignored — on the async path it is a snapshot taken when the operation was
  queued (`pending: true`, pre-operation `state`/`balance`), so `StatusAction` re-fetches instead of
  trusting it. `CancelAction` catches **nothing** — cancelling an already captured or cancelled payment
  fails with a `ValidationException` and that reaches the caller. It used to try to swallow that case
  for idempotency by matching the error message, which was both fragile (Quickpay's wording drifted from
  "Transaction in wrong state for this operation" to "Validation error: Payment is not in a valid state
  for cancel", so the guard silently never fired) and wrong in substance: reporting success for a
  payment whose money is still held. A caller wanting a no-op can catch the typed exception itself.
- **Notify** → `NotifyAction` — entry point for Quickpay's server-to-server callback. **Quickpay routes
  callbacks to two different urls** (verified live, 2026-08): the payment-window authorize goes to the
  per-payment `callback_url` on the link — the notify token `AuthorizeAction` mints — while API-initiated
  `capture`/`refund`/`cancel` go to the **account-wide** url (manager → Settings → Integration), which is
  empty by default, so those callbacks are simply not delivered. That url is static for every payment and
  cannot carry a `payum_token`, so Payum's token routing cannot serve it; an endpoint for it must resolve
  the payment from the body's `order_id` (`order_prefix` + payum number) and execute `Notify` against
  that model — `NotifyAction` needs only the model. See `examples/e2e/listen.php` and
  `docs/UPGRADE-2.0.md`. It fetches the raw
  body + `QuickPay-Checksum-Sha256` header (via Payum's `GetHttpRequest`), **verifies the HMAC signature**
  with the SDK `CallbackValidator`, and rejects an invalid/unsigned callback with a 400 `HttpResponse`
  before delegating to the internal `ConfirmPayment` request.
- **ConfirmPayment** (internal, `src/Request/Api/` + `Action/Api/ConfirmPaymentAction`) — when
  `auto_capture` is on and the latest operation is an approved authorize whose amount matches, it captures
  automatically.
- **Sync** → `SyncAction` — Payum's standard "refresh the details from the gateway" request. Fetches by
  `quickpayPaymentId` and writes the scalar snapshot (`balance`, `state`). A model without a
  `quickpayPaymentId` has nothing to sync, so it is a no-op rather than a throw. Every action that
  already fetches the payment (`StatusAction`, `ConfirmPaymentAction`, and `RefundAction` on its default
  path) also persists `balance` — it costs no extra call and it is the one figure Payum's marks cannot
  express, so downstream consumers do not have to re-fetch just to learn it.
- **GetStatus** → `StatusAction` — maps the SDK `PaymentState` + latest operation to Payum marks
  (`markCaptured`, `markRefunded`, `markAuthorized`, `markFailed`, etc.). The refund branch is **not**
  decided by the operation alone: it consults the payment's `balance` (captured minus refunded), so a
  partial refund stays `markCaptured` and only a zero balance is `markRefunded`. Payum has no partial
  mark, and reporting a partly refunded payment as fully refunded is a lie a shop acts on. A null
  `balance` falls back to treating the refund as full.

### Amounts (`src/Amounts.php`)

Payum's `Capture`/`Refund` requests carry no amount, so the details array is the only channel a caller
has for a **partial** operation. `Amounts::forOperation($details, 'refund_amount')` reads the per-operation
override key when present and falls back to `amount`, rejecting anything non-numeric or non-positive.
The keys are `capture_amount` and `refund_amount`.

**`RefundAction` does not use that fallback.** With no explicit `refund_amount` it fetches the payment
and refunds the `balance`, because falling back to the full `amount` is guaranteed to be rejected once
anything has been refunded — a payment is refundable only up to what is captured. Nothing refundable
throws a `LogicException` naming the payment and balance. An explicit amount skips the fetch.

Quickpay accepts **repeated** captures against one authorization and repeated refunds against what is
captured — verified live 2026-08 (authorize 1000 → capture 250 → capture 250 → refund 250 → refund 250,
balance 0). `StatusAction` reports `captured` throughout and only `refunded` at a zero balance, so the
mark says "something is held", never how much.

`Amounts::consume()` deletes the key, and the actions call it **only after the API accepted the call** —
details are usually persisted with the payment, so a leftover key would outlive its operation and make
the next one silently partial, while a failed call must keep the instruction for a retry. The deliberate
trade-off: re-executing a *successful* partial operation against a reloaded payment now falls back to
the full amount. That is the rarer case, but it is the more expensive one, so the docs tell callers to
set the key per operation rather than lean on what a previous one left behind.

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

## End-to-end harness (`examples/e2e/`)

Committed dev tooling that exercises the gateway against the **real** Quickpay API — deliberately
**outside** the phpstan/ecs/dep-analyser paths (`src` + `tests`), so check those scripts with `php -l`.
`bootstrap.php` builds a framework-less Payum (`PayumBuilder` + `FilesystemStorage` under
`examples/e2e/var/payum`, so the CLI scripts and the web listener share state) and everything runs
through real Payum requests, not the SDK directly. Scripts: `composer e2e:smoke` (no browser/tunnel —
create + link + status), `e2e:listen` (built-in server serving the Payum token urls), `e2e:create`
(full flow, prints the payment-window url), `e2e:operate` (`status|capture|refund|cancel`).

Two things it encodes that are easy to get wrong:
- `HeaderAwareGetHttpRequestAction` — payum/core's plain-PHP bridge does **not** populate
  `GetHttpRequest::$headers`, so outside Symfony every callback would be rejected as unsigned. It is
  registered via `addCoreGatewayFactoryConfig(['payum.action.get_http_request' => ...])`.
- `listen.php` handles **both** callback shapes: the payment-window one carries a `payum_token`, while a
  callback sent to the account-wide url cannot, so it resolves the payment from the body's `order_id`
  instead. The log tags each `via=token` or `via=order_id`, which is how the two-url routing above was
  established in the first place.

`e2e:operate` takes an optional amount on `capture` and `refund`, setting the `capture_amount` /
`refund_amount` details keys.

Credentials come from a gitignored `.env.local` (see `.env.local.example`); `examples/e2e/var/` is
gitignored too. Quickpay has no sandbox — you use the production API key and a test **card**.

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
