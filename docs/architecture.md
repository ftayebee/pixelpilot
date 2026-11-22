# Architecture

PixelPilot is a single Laravel 13 application with a Vue 3 + Inertia frontend, PostgreSQL, a queue worker and the scheduler. There's no separate API or Node backend; the browser never talks to Pixelforge.

```
Browser (Vue/Inertia) ──▶ Laravel controllers ──▶ services ──▶ PixelforgeClient ──▶ Pixelforge API
                                                     │                                  │
                                                     ▼                                  │ webhooks
                                                PostgreSQL ◀── queue jobs ◀── /webhooks/pixelforge
                                                     ▲
                                                scheduler (polling, sweeper, usage sync)
```

## Code map

| Path | Responsibility |
|---|---|
| `app/Services/Pixelforge/PixelforgeClient.php` | The only HTTP client for Pixelforge: auth, timeouts, documented retries, error mapping, log hygiene |
| `app/Services/Pixelforge/Data/*` | Typed views of Pixelforge's pricing and generation objects |
| `app/Services/Pricing/PricingService.php` | All pricing maths: estimates, costs, markup (BCMath) |
| `app/Services/Pricing/PricingCatalog.php` | Current pricing from `GET /v1/pricing`, cached, with a last-known fallback |
| `app/Services/Pricing/ChargeRecorder.php` | Writes the one charge row for a succeeded generation |
| `app/Services/Generations/GenerationCreator.php` | Validated input → local generation + estimate + credit reservation |
| `app/Services/Generations/GenerationSubmitter.php` | Sends a generation to Pixelforge with its Idempotency-Key; schedules retries |
| `app/Services/Generations/GenerationSynchronizer.php` | Applies any Pixelforge snapshot (create response, webhook, poll): row lock, forward-only status, charge/release |
| `app/Services/Generations/GenerationPoller.php` | Polling fallback with claim-by-`SKIP LOCKED` and backoff |
| `app/Services/Generations/GenerationImages.php` | Where an image is served from (Pixelforge URL or our stored copy) |
| `app/Services/Pixelforge/Webhooks/*` | Signature verification, inbox (dedupe + persist), processor |
| `app/Services/Credits/CreditLedger.php` | Append-only credit ledger: purchase, reserve, release, settle |
| `app/Services/Admin/*` | Operator report and `/v1/usage` reconciliation |
| `app/Support/Money.php` | Exact decimal arithmetic; rejects anything that isn't a decimal string |
| `resources/js/pages/*` | Inertia pages; `resources/js/components/*` shared UI |

Controllers stay thin: validate, call a service, render or redirect.

## Data model

| Table | Purpose | Key constraints |
|---|---|---|
| `users` | accounts, `is_admin`, `credit_balance` | `is_admin`/`credit_balance` not mass-assignable |
| `generations` | one row per image request, created **before** calling Pixelforge | unique `uuid`, `idempotency_key`, `provider_generation_id`, `(user_id, client_request_id)`; CHECKs on status/size/quality, "failed is free", "actuals only when final"; trigger: final status and recorded amounts are immutable |
| `generation_charges` | the financial record of a success | unique `generation_id`; CHECK cost = Σ tokens × rate / 1e6; CHECK charge = cost × markup; append-only |
| `webhook_events` | every verified event, verbatim | unique `event_id` |
| `credit_transactions` | credit ledger | unique `reference`; one reservation/release/charge per generation; sign CHECKs; append-only |
| `provider_usage_days` | copy of `GET /v1/usage` | unique `(key_fingerprint, date)` |
| `jobs`, `failed_jobs`, `cache`, `sessions` | Laravel infrastructure (database drivers) | |

Money columns are `NUMERIC(24,12)`. See `docs/pricing.md`.

## Generation lifecycle

```
submitting ──▶ queued ──▶ processing ──▶ succeeded   (charged once)
     │                                └─▶ failed      ($0, reservation released)
     └─▶ failed  (rejected: content policy, daily quota, gave up retrying)
```

`submitting` is ours: the row exists but Pixelforge hasn't confirmed it. Users see it as "Queued".

1. **Create** (`POST /generations`): validate against the live pricing catalogue; compute the estimate; in one transaction insert the generation and reserve credits. A per-form `client_request_id` makes a double-submit return the same generation.
2. **Submit**: `POST /v1/generations` with the generation's own `Idempotency-Key` and `metadata.pixelpilot_generation = <uuid>`. 503s and network failures are retried inline (same key, capped, honouring `Retry-After`). Anything still retryable is handed to the `SubmitGeneration` job with backoff. Non-retryable errors mark it failed at $0.
3. **Track**: webhooks are the primary path (`docs/webhooks.md`). The poller checks anything overdue.
4. **Finish**: `GenerationSynchronizer` applies the final state exactly once.

## Decisions and trade-offs

**Record locally before calling the provider.** Every request has a row (and a reservation) before any network call, so there's always something to attach retries, webhooks and charges to, and nothing is lost if the process dies mid-request. The cost is a short-lived `submitting` state that the UI shows as "Queued".

**Idempotency key per generation, not per request.** Retries, the queued retry job and the poller's re-submit all reuse the same key, so an accepted-but-timed-out request can never create a second (billed) image. The payload is rebuilt from the stored row, so the parameters are identical and we never trigger `409 idempotency_key_reused`.

**Our UUID in metadata.** It lets a webhook find its generation even when it arrives before the create response is stored. We only trust it when the prompt matches as well.

**Status rank instead of event timestamps.** Pixelforge's states are monotonic, so "furthest along wins, final is final" is always correct, and it doesn't depend on clock skew or delivery order.

**Database-enforced money invariants.** Unique indexes, CHECKs and triggers make double charges or edited amounts impossible even with a bug in PHP. The trade-off is that a broken invariant surfaces as a failed job rather than a silently wrong number, which is what we want.

**Synchronous first submission.** The user gets an immediate answer for the common cases (accepted, blocked by content policy). Overloads and limits fall back to the queue without blocking the request for long (inline waits are capped at 5 s).

**Polling as a fallback, not a loop.** Each generation is polled on a backoff schedule (45 s doubling to 10 min) only while pending, claimed with `FOR UPDATE SKIP LOCKED`, and skipped when the account is near its per-minute limit.

**Prices as strings end to end.** The server sends exact decimal strings; the browser only formats them (and compares balances with `BigInt`). Charts convert to numbers purely to position marks.

**Database queue by default.** It keeps the deployment to Postgres + PHP; switching to Redis is an env change.

## Assumptions (where the assessment left room)

- **Fractions of a cent**: never rounded in storage; shown trimmed in the UI. See `docs/pricing.md`.
- **The estimate is a range** from the documented minimum to maximum tokens; credits reserve the maximum.
- **Content-policy rejections** are kept in history as failed at $0, so the user can see what happened and retry. They count as failures in the success rate.
- **Daily-quota rejections** fail immediately (retrying won't help until the next UTC day).
- **Generations pending for 48 h** are closed as failed at $0 instead of being polled forever.
- **Report days** are UTC days of completion, because that's when Pixelforge bills. Reconciliation is per API key (environment), never per user.
- **Images**: Pixelforge URLs are used until they expire; optional storage copies them to any Laravel disk (e.g. S3).
- **Admins** are promoted from the CLI; there's no UI to grant admin rights.
- **Credits** use simulated purchases only; a shortfall above the reservation is recorded and flagged, never hidden.
