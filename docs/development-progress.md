# Development progress

Each milestone was implemented, tested and pushed before the next one started.

| # | Milestone | Status |
|---|-----------|--------|
| 1 | Initialize project (Laravel 13, Inertia 3, Vue 3 + TS, Tailwind 4) | Done |
| 2 | Environment, PostgreSQL, Pixelforge config, health check | Done |
| 3 | User authentication | Done |
| 4 | Dashboard layout and navigation | Done |
| 5 | Dashboard overview | Done |
| 6 | Pixelforge API client | Done |
| 7 | Image generation workflow | Done |
| 8 | Pricing engine | Done |
| 9 | Generation history | Done |
| 10 | Webhook signature verification | Done |
| 11 | Reliable webhook processing | Done |
| 12 | Polling fallback | Done |
| 13 | Generation and pricing tests | Done |
| 14 | Webhook and failure tests | Done (live run verified) |
| 15 | Admin usage report (stretch) | Done |
| 16 | Credit balances (stretch) | Done |
| 17 | Setup and deployment guide | Done |
| 18 | Final production checks | Done (deployment pending server access) |

## Assessment checklist

| Requirement | Status |
|---|---|
| Public GitHub repository | ✅ github.com/ftayebee/pixelpilot (visibility is set on GitHub) |
| Public deployed application | ⏳ Configs and guide ready (`deploy/`, `docs/deployment.md`); needs the server |
| User authentication | ✅ |
| Image generation page: prompt, size, quality, reference URLs | ✅ |
| Estimated pricing before submission | ✅ range with token breakdown |
| Pixelforge API integration | ✅ client verified live against `/v1/pricing` and the 401 error shape |
| Actual usage-based pricing, 25% markup, exact decimals | ✅ BCMath + `NUMERIC(24,12)` + DB CHECKs |
| Failed generations cost zero | ✅ DB CHECK |
| Generation history with queued/processing/succeeded/failed | ✅ |
| Webhook endpoint, HMAC verification, timestamp window | ✅ |
| Duplicate and out-of-order events | ✅ unique event id, forward-only status |
| Exactly-once charging | ✅ unique charge per generation + immutability triggers |
| Queue-based processing, polling fallback | ✅ |
| Generation idempotency, rate-limit handling, concurrency protection | ✅ |
| Tests passing | ✅ 196 tests |
| Commits separated by feature | ✅ 18 milestone commits |
| Live keyword smoke test | ✅ all six keywords pass; costs match `/v1/usage` exactly |

| Stretch | Status |
|---|---|
| Admin analytics: daily and per-user charges, costs, profit | ✅ |
| `GET /v1/usage` reconciliation | ✅ per key, differences shown |
| Credit balances, fake purchase, reservation and settlement, insufficient balance | ✅ |

## Security review (milestone 18)

- No secrets in tracked files or git history; `.env` is ignored; `.env.example` holds placeholders only.
- Pixelforge key and webhook secret are server-side only; logs never include them or prompts.
- Every generation, image and retry route checks ownership (`GenerationPolicy`); admin routes use the `admin` middleware; admin route names aren't sent to non-admin browsers.
- `is_admin` and `credit_balance` aren't mass-assignable; models are filled from validated, explicit fields.
- No raw SQL with interpolated input; search escapes `LIKE` wildcards.
- Vue escapes all output; no `v-html`.
- CSRF on every browser form; only the signed webhook route is exempt.
- Login, registration, password reset, generation, estimate, purchase and usage sync are rate limited.
- `/health` reports pass/fail only. No debug endpoints. `APP_DEBUG=false` in production.
- Financial tables are append-only/immutable at the database level.
- `composer audit` / `npm audit`: no advisories.

## Environment notes

- Built on PHP 8.5 (XAMPP), Composer 2.11, Node 26, PostgreSQL 18 (portable binaries, not a service).
- Redis wasn't available locally, so sessions, cache and queues use the `database` driver; switching to Redis is an env change.
- The test suite runs against a real PostgreSQL database (`pixelpilot_test`) so row locks, unique constraints and `NUMERIC` columns behave exactly as in production.
