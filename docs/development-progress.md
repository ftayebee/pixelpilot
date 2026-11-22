# Development progress

Each milestone is implemented, tested and pushed before the next one starts.

| # | Milestone | Status |
|---|-----------|--------|
| 1 | Initialize project (Laravel 13, Inertia 3, Vue 3 + TS, Tailwind 4) | Done |
| 2 | Environment, PostgreSQL, Pixelforge config, health check | Done |
| 3 | User authentication | Done |
| 4 | Dashboard layout and navigation | Done |
| 5 | Dashboard overview | Done |
| 6 | Pixelforge API client | Pending |
| 7 | Image generation workflow | Pending |
| 8 | Pricing engine | Pending |
| 9 | Generation history | Pending |
| 10 | Webhook signature verification | Pending |
| 11 | Reliable webhook processing | Pending |
| 12 | Polling fallback | Pending |
| 13 | Generation and pricing tests | Pending |
| 14 | Webhook and failure tests | Pending |
| 15 | Admin usage report (stretch) | Pending |
| 16 | Credit balances (stretch) | Pending |
| 17 | Setup and deployment guide | Pending |
| 18 | Final production checks | Pending |

## Environment notes

- Local machine: PHP 8.5 (XAMPP), Composer 2.11, Node 26, PostgreSQL 18 (portable binaries, not a service).
- Redis is not installed locally, so sessions, cache and queues use the `database` driver. The config switches to Redis by changing the `*_DRIVER`/`QUEUE_CONNECTION` env vars.
- The test suite runs against a real PostgreSQL database (`pixelpilot_test`) so row locks, unique constraints and `DECIMAL` columns behave exactly as in production.
