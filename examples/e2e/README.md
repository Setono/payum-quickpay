# End-to-end test harness

Verifies the **whole gateway** against the real Quickpay API — the part the unit suite deliberately
can't reach, because it is fully mocked. Everything here goes through a real, framework-less Payum:
the requests you execute are `Authorize` / `Capture` / `Refund` / `Cancel` / `Notify` /
`GetHumanStatus`, and the actions in `src/` do the rest.

```
   e2e:create ──► Authorize ──► ConvertPaymentAction  (creates the Quickpay payment)
                              └► AuthorizeAction      (creates the link, HttpRedirect)
                                       │
   browser (test card) ────────────────┘
                                       │
   Quickpay ──► POST notify token url ─┴─► NotifyAction  (verifies the HMAC)
                                            └► ConfirmPaymentAction (auto-captures)
   e2e:operate ──► Capture / Refund / Cancel / GetHumanStatus
```

## How Quickpay test mode works (important)

- There is **no sandbox and no separate test API key.** You use your **real production API key**.
- A payment becomes a *test* payment purely by paying with a **special test card number** (below).
  It is marked `test_mode: true` and never touches real money.
- Test payments fire **real, signed callbacks** exactly like production — HMAC-SHA256 over the raw body
  using your **account private key** (manager → Settings → Integration), which is **different** from
  the API key.
- The browser redirect to the after-url carries **no payment data** and can arrive *before* the
  callback. Trust only the verified callback, or `e2e:operate status` (which re-fetches).

## The non-Symfony gotcha this harness exists to prove

`NotifyAction` reads the `QuickPay-Checksum-Sha256` header off `GetHttpRequest::$headers`, and among
payum/core's bridges **only the Symfony one populates that property**. The plain-PHP bridge does not,
so a plain-PHP consumer that wires nothing extra has every callback rejected as unsigned with a `400`.

`HeaderAwareGetHttpRequestAction` in this directory is the ~20 lines that fix it, registered via
`addCoreGatewayFactoryConfig(['payum.action.get_http_request' => ...])` in `bootstrap.php`. If you
integrate this gateway outside Symfony, you need the equivalent — see `docs/UPGRADE-2.0.md`.

## Prerequisites

- Dev dependencies installed (`composer install`). The harness relies on the guzzle + guzzle7-adapter
  dev deps being discoverable as the PSR-18/PSR-17 implementations — nothing to wire manually.
- Two Quickpay credentials, in a gitignored `.env.local` at the repo root (loaded automatically), or
  exported as env vars (real env vars win over the file):
  ```bash
  cp .env.local.example .env.local   # then fill in QUICKPAY_API_KEY + QUICKPAY_PRIVATE_KEY
  ```
- For the callback half only: a free [expose.dev](https://expose.dev) account and its auth token, or
  any other way to expose `localhost:8000` over public HTTPS.

## Quick real-API check (no browser, no tunnel)

Builds the gateway, creates a real payment and a real payment link, and reports the Payum status.
Charges nothing — no card is entered:

```bash
composer e2e:smoke
```

A failure here is a wiring or credentials problem, not a payment problem. `401 Invalid API key` means
exactly what it says.

## The full flow (three terminals)

### Terminal A — the listener

Bind to `0.0.0.0` so a tunnel container can reach it:

```bash
composer e2e:listen
# = php -S 0.0.0.0:8000 examples/e2e/listen.php
```

It serves the Payum token urls: `POST /notify` (the callback, handled by the real gateway) and
`GET /done` (where the customer lands). Every callback is logged to the terminal and to
`examples/e2e/var/callbacks.log`, also viewable at `http://localhost:8000/`.

### Terminal B — the tunnel

`expose/` holds a small client image. Build it once, then share the listener:

```bash
docker build -t expose-client examples/e2e/expose
docker run --rm -e EXPOSE_TOKEN=<your-token> expose-client \
  share http://host.docker.internal:8000
```

Do **not** pass `--server=eu-1` on the free tier — that region is Pro-only and fails with
*"This server region requires an Expose Pro license"*. Omitting `--server` uses the free shared server.

We build our own image because **the Expose project's own Dockerfile builds the Expose _server_, not
the share client**. The entrypoint re-applies `EXPOSE_TOKEN` via `expose token` on every run, since
that config does not persist in an ephemeral container.

On macOS / Docker Desktop reach the host via `host.docker.internal` — `--network host` does **not**
work there. A LAN IP (`http://192.168.2.100:8000`) also works if your firewall allows it. Copy the
public `https://<random>.<region>.sharedwithexpose.com` URL — on the free tier it changes every
session, which is why it is never hardcoded.

> No Docker? Install the client on the host instead:
> ```bash
> composer global require exposedev/expose
> expose token <your-token>
> expose share http://localhost:8000
> ```

### Terminal C — create a payment

The base URL is what Payum mints its token urls on, so it must be the tunnel URL:

```bash
QUICKPAY_CALLBACK_BASE=https://<random>.<region>.sharedwithexpose.com \
  composer e2e:create -- 1000 DKK
```

It prints the Payum payment number, the Quickpay id, the callback url and a **payment-window URL** —
open that in a browser and pay with a test card.

## Test cards

Any **valid-looking** expiry and CVD work. The card number's last digits select the scenario; VISA
shown, other brands follow the same pattern (full list in the
[test appendix](https://learn.quickpay.net/tech-talk/appendixes/test/)):

| VISA card | Scenario |
|---|---|
| `1000 0000 0000 0008` | Approved |
| `1000 0000 0000 0016` | Rejected |
| `1000 0000 0000 0024` | Card expired |
| `1000 0000 0000 0032` | Capture rejected |
| `1000 0000 0000 0040` | Refund rejected |
| `1000 0000 0000 0057` | Cancel rejected |
| `1000 0000 0000 0073` | 3-D Secure required |
| `1000 0000 0000 0099` | Delayed in queue 60s (async / pending) |

## Drive the rest of the lifecycle

Address the payment by the **Payum number** printed by `e2e:create` (not the Quickpay id):

```bash
composer e2e:operate -- status  <payumNumber>
composer e2e:operate -- capture <payumNumber>
composer e2e:operate -- refund  <payumNumber> 250
composer e2e:operate -- cancel  <payumNumber>
```

Operations are asynchronous unless you set `QUICKPAY_SYNCHRONIZED=1`, so a `status` immediately after
a capture may still show the pre-operation state — the settled state arrives in the callback. Run
`status` again a moment later, or turn the flag on to compare the two modes.

## What to verify

- **Callback accepted:** Terminal A logs `CALLBACK OK` with the Payum status (`authorized`, `captured`,
  …) and the stored `quickpayPaymentId` / `order_id` / `amount`.
- **Which url each callback used.** The log tags every callback `via=token` or `via=order_id`. Verified
  live (2026-08): the payment-window authorize arrives `via=token` (the per-payment `callback_url` on
  the link), while `capture` / `refund` / `cancel` arrive `via=order_id` — Quickpay sends those to the
  **account-wide** url under manager → Settings → Integration. Leave that field empty (the default) and
  operation callbacks are delivered nowhere at all. To see them, point it at
  `https://<your-tunnel>/notify` for the session, and remember to clear it afterwards or Quickpay keeps
  POSTing at a dead tunnel.
- **Callback rejected:** replay the same callback with a tampered checksum and the listener returns
  **400** and logs `CALLBACK REJECTED`. This is the security property `NotifyAction` exists for — a
  forged callback must never move a payment.
- **`auto_capture`:** with `QUICKPAY_AUTO_CAPTURE=1`, an approved authorize is captured by
  `ConfirmPaymentAction` as soon as the callback lands — the status goes to `captured` without you
  running `capture`.
- **Details stay scalar:** `e2e:operate status` prints the details array; no objects, and
  `quickpayPaymentId` is the only handle the gateway needs.
- **Idempotent cancel:** `cancel` on an already captured payment succeeds as a no-op (`CancelAction`
  swallows Quickpay's "Transaction in wrong state for this operation").
- **Status mapping:** compare `status` against the payment in the Quickpay manager across the card
  scenarios above — approved, rejected, capture-rejected.

## Security

Secrets come only from the environment / `.env.local` and are never written to disk by these scripts.
`examples/e2e/var/` — the filesystem storage and the callback log, which contain payment data — is
gitignored. If you enable test transactions on a production account, turn them back off under
Settings → Integration when you are done.
