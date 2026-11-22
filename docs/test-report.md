# Test report

## How to run

```bash
php artisan test                     # full suite (needs the pixelpilot_test PostgreSQL database)
php artisan test --filter=Pricing    # a subset
vendor/bin/pint --test               # code style
npm run typecheck                    # Vue/TypeScript
```

The suite runs against **real PostgreSQL**, not SQLite, so row locks, `SKIP LOCKED`, unique indexes, `NUMERIC` columns, CHECK constraints and the immutability triggers behave exactly as in production. Pixelforge is faked with `Http::fake()` using payloads copied from the documentation (`tests/Support/PixelforgeFixtures.php`), so no test needs a live account or network access.

## Latest run

| | |
|---|---|
| Tests | 196 passed, 0 failed |
| Assertions | 21,033 |
| Duration | ~20–30 s |
| Dependencies | `composer audit` and `npm audit`: no advisories |
| Style | Pint: passed |
| Types | vue-tsc: no errors |

## What's covered

| Area | Test class |
|---|---|
| Registration, login, logout, throttling, password reset, admin flag not mass-assignable | `Auth\AuthenticationTest` |
| Profile and password settings | `SettingsTest` |
| Dashboard numbers are real, exact and per user | `DashboardTest` |
| Pixelforge client: headers, idempotency, retries, every documented error code, log hygiene | `Pixelforge\PixelforgeClientTest` |
| Generate page, validation, estimate endpoint, submission, idempotency, rejections | `Generations\GenerationWorkflowTest` |
| Estimates, costs, markup, fractional cents, large counts, rounding | `Unit\PricingServiceTest`, `Unit\MoneyTest` |
| Actual charge ≤ / ≥ estimate for 2,000 random requests; exact ±15% text bounds for every prompt length | `Unit\EstimateBoundsTest` |
| One charge per generation, failures free, DB constraints and triggers | `Generations\ChargingTest` |
| History: pagination, search, filters, sort, ownership, image storage | `Generations\GenerationHistoryTest` |
| Full lifecycle over HTTP, retry flow | `Generations\GenerationLifecycleTest` |
| Signature verification | `Unit\PixelforgeWebhookVerifierTest`, `Webhooks\WebhookSignatureTest` |
| Webhook storage and processing | `Webhooks\WebhookProcessingTest` |
| Polling fallback | `Generations\PollingTest` |
| Assessment failure scenarios | `Scenarios\FailureScenariosTest`, `Scenarios\ConcurrencyTest` |
| Admin report and `/v1/usage` reconciliation | `Admin\AnalyticsTest` |
| Credits: purchase, reserve, settle, release, shortfall, ledger integrity | `Credits\CreditsTest` |
| Admin route names not exposed to members | `ExposedRoutesTest` |
| Health endpoint doesn't leak configuration | `HealthCheckTest` |

## Assessment scenarios

| # | Scenario | Expected | Covered by |
|---|---|---|---|
| 1 | `[blocked]` | 400 shown on the prompt field, input kept, recorded as failed at $0 | `GenerationWorkflowTest::test_content_policy_rejection_is_shown_on_the_prompt_and_costs_nothing` |
| 2 | `[overloaded]` / 503 | Retried inline with the same Idempotency-Key, then handed to a queued retry; nothing billed | `PixelforgeClientTest::test_overloaded_create_is_retried_with_the_same_idempotency_key`, `GenerationWorkflowTest::test_overloaded_provider_is_retried_then_queued_for_later_with_the_same_key` |
| 3 | Rate limit (429) | Short `Retry-After` waited inline; long ones deferred to the queue | `PixelforgeClientTest::test_rate_limit_waits_for_retry_after_then_succeeds`, `FailureScenariosTest::test_rate_limit_with_long_retry_after_is_deferred_to_the_queue` |
| 4 | Concurrency limit (429) | Never blocks the request; queued retry; after the attempt cap, failed at $0 | `GenerationWorkflowTest::test_concurrency_limit_queues_a_retry_after_the_given_delay`, `FailureScenariosTest::test_submission_gives_up_after_the_attempt_cap_at_no_cost` |
| 5 | Accepted, then fails | Failed, $0, error shown, retry offered | `GenerationLifecycleTest::test_accepted_generation_that_fails_later_is_free` |
| 6 | Success | Charged once from actual usage, within the estimate | `GenerationLifecycleTest::test_successful_generation_end_to_end` |
| 7 | `[slow]` (60–90 s) | Stays processing through polls, no premature failure | `FailureScenariosTest::test_slow_generation_is_not_failed_prematurely` |
| 8 | Duplicate webhook | Stored once, charged once | `WebhookProcessingTest::test_duplicate_delivery_is_recorded_once_and_charged_once`, `FailureScenariosTest::test_duplicate_keyword_storm_charges_exactly_once` |
| 9 | Out-of-order webhook | Late `processing` / `succeeded` can't move a final generation | `WebhookProcessingTest::test_out_of_order_processing_after_succeeded_is_ignored`, `::test_failed_then_late_succeeded_stays_failed_and_free`, `ChargingTest::test_success_never_reverts` |
| 10 | Invalid signature | 400, nothing stored | `WebhookSignatureTest` (missing, modified payload, re-encoded JSON, wrong secret) |
| 11 | Timestamp outside window | 400 | `WebhookSignatureTest::test_expired_timestamp_is_rejected`, `PixelforgeWebhookVerifierTest` (past and future) |
| 12 | Delivery retry | Redelivery processes an event that couldn't be matched the first time | `FailureScenariosTest::test_redelivered_event_is_processed_once_the_generation_can_be_matched` |
| 13 | Webhook before create response saved | Matched by metadata UUID; the late `queued` response doesn't override `processing` | `WebhookProcessingTest::test_webhook_arriving_before_the_create_response_is_matched_by_metadata`, `FailureScenariosTest::test_webhook_during_the_create_request_is_not_overwritten_by_the_response` |
| 14 | Duplicate submission | Same form token → one generation, one API call | `GenerationWorkflowTest::test_double_submit_with_the_same_form_token_creates_one_generation` |
| 15 | Network timeout | Retried with the same key; replay returns the original; never two generations | `FailureScenariosTest::test_network_timeouts_on_create_never_produce_a_second_generation`, `::test_persistent_network_failure_leaves_the_generation_retryable` |
| 16 | Polling recovery | Missed success/failure recovered by polling, charged once | `PollingTest::test_poll_recovers_a_missed_success_and_charges_once`, `::test_poll_recovers_a_missed_failure_at_zero_cost` |
| 17 | Simultaneous processing | Second worker blocks on the row lock; same event can't be processed twice; schedulers claim disjoint rows | `ConcurrencyTest` (uses a second real DB connection to hold locks) |
| 18 | Duplicate charging | Unique index, immutability triggers, CHECKs | `ChargingTest::test_database_refuses_*`, `::test_duplicate_success_does_not_charge_twice` |

## Live checks against Pixelforge

| Check | Result |
|---|---|
| `GET /v1/pricing` returns the documented rates and token tables, parsed by `PixelforgeClient::pricing()` | Verified 2026-11-22 |
| Invalid key → `401 invalid_api_key` with the documented error shape, mapped to a non-retryable `PixelforgeException` | Verified 2026-11-22 |
| Real signed webhook (dashboard "Send test event") through a Cloudflare tunnel | Verified 2026-11-22: HTTP 204 on attempt #1, signature accepted, stored as `ignored` (ping) |
| Keyword smoke test with a real key | Verified 2026-11-22, all six keywords pass (below) |
| Our provider cost vs `GET /v1/usage` for the key | Verified 2026-11-22: exact match, $0.043005000 vs $0.043005000, 3 vs 3 billed generations |
| Production (https://pixelpilot.boigharbd.com, its own key): signed test events from the dashboard | Verified 2026-11-22: HTTP 204 |
| Production keyword smoke test, webhooks and reconciliation | Verified 2026-11-22, all six keywords pass; admin report shows "Matches Pixelforge" (below) |

`pixelforge:smoke` sends each keyword through the real app path (estimate, credit reservation, submit, webhook or poll, charge). For each success it checks that there's exactly one charge row, that charge = cost × 1.25, and that the charge sits inside the estimate; for each failure it checks that it's free with no charge row.

Live run, local key, webhooks delivered through a tunnel with a queue worker running:

| Keyword | Submission | Final status | Error | Charged | Checks |
|---|---|---|---|---|---|
| `[succeed]` | accepted | succeeded | — | $0.020365625 | ok |
| `[fail]` | accepted | failed | `generation_failed` | $0 | ok |
| `[blocked]` | rejected (400) | failed | `content_policy_violation` | $0 | ok |
| `[overloaded]` | 503 on every attempt; retried up to the cap | failed | `overloaded` | $0 | ok |
| `[duplicate]` | accepted | succeeded | — | $0.01805625 | ok |
| `[slow]` | accepted | succeeded (after 60–90 s) | — | $0.015334375 | ok |

Webhook log for the run: 3 `generation.succeeded` events arrived in 4 deliveries and 4 `generation.processing` events in 5. The duplicates were stored once, and the run produced exactly 3 charge rows for 3 successes. Users were charged $0.05375625 in total against $0.043005 of Pixelforge cost: $0.01075125 profit, exactly 25%.

### Production run

Same command inside the `app` container on the live server, with the production key and webhooks delivered straight to `https://pixelpilot.boigharbd.com/webhooks/pixelforge`:

| Keyword | Submission | Final status | Error | Charged | Pixelforge id | Checks |
|---|---|---|---|---|---|---|
| `[succeed]` | accepted | succeeded | — | $0.015971875 | `gen_01M8282VNPMSX4QSD5QNW5FWP1` | ok |
| `[fail]` | accepted | failed | `generation_failed` | $0 | `gen_01M8283PPAYF5M3H1M5X99QFAS` | ok |
| `[blocked]` | rejected (400) | failed | `content_policy_violation` | $0 | — | ok |
| `[overloaded]` | 503 on every attempt; retried up to the cap | failed | `overloaded` | $0 | — | ok |
| `[duplicate]` | accepted | succeeded | — | $0.01418125 | `gen_01M8285ZXRHYK7R53BRS3K979Y` | ok |
| `[slow]` | accepted | succeeded | — | $0.017575 | `gen_01M8286AT8E1SQ1ZZ2VKJQ4A2V` | ok |

Webhooks received: 3 `generation.succeeded`, 1 `generation.failed`, 4 `generation.processing` and 2 pings, all processed. After `pixelforge:sync-usage`, `/admin/analytics` shows our provider cost $0.0381825 against $0.0381825 from `GET /v1/usage`, 3 of 3 billed generations: "Matches Pixelforge". Users were charged $0.047728125, so profit is $0.009545625, exactly 25%.
