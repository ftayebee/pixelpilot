# Pixelforge API contract

Summary of the parts of <https://pixelforge.promptedit.dev/docs> that PixelPilot depends on, as read on 2026-11-22. If the live docs and this file disagree, the docs win; update this file.

## Basics

| | |
|---|---|
| Base URL | `https://pixelforge.promptedit.dev/v1` |
| Auth | `Authorization: Bearer <api key>`. Missing/invalid/revoked → `401 invalid_api_key`. |
| Bodies | JSON in, JSON out |
| Timestamps | ISO 8601, UTC (`2026-11-04T14:03:11Z`) |
| Ids | `gen_…` generations, `evt_…` webhook events |
| Money | Exact decimal **strings** in USD, never floats. The API never returns a price, only token usage and rates. |

One API key per environment. Each key has its own webhook URL, signing secret and usage report.

## Endpoints used

### `GET /v1/pricing` (no auth)

```json
{
  "object": "pricing", "currency": "usd", "unit": "per_1m_tokens",
  "rates": { "input_text_tokens": "5.00", "input_image_tokens": "10.00", "output_tokens": "40.00", "reasoning_tokens": "12.50" },
  "tokens": {
    "characters_per_text_token": 4,
    "text_token_variance": 0.15,
    "per_reference_image": 765,
    "output": { "low": {"1024x1024": 280, "1536x1024": 420, "1024x1536": 420},
                "medium": {"1024x1024": 1100, "1536x1024": 1650, "1024x1536": 1650},
                "high": {"1024x1024": 4300, "1536x1024": 6450, "1024x1536": 6450} },
    "reasoning": { "low": {"min": 0, "max": 500}, "medium": {"min": 200, "max": 2000}, "high": {"min": 1000, "max": 8000} }
  }
}
```

Rates can change, so they're read from here for estimates. A generation is billed at the `rates` returned on the generation itself (fixed at creation).

### `POST /v1/generations`

| Param | Rules |
|---|---|
| `prompt` | required string, ≤ 4,000 characters |
| `size` | `1024x1024` (default), `1536x1024`, `1024x1536` |
| `quality` | `low`, `medium` (default), `high` |
| `reference_images` | optional array of http(s) URLs, ≤ 4. Not downloaded; each billed as 765 input image tokens. |
| `metadata` | optional object of string→string, ≤ 20 keys, values ≤ 500 chars. Echoed on the generation and every webhook. |

Unknown parameters → `422 validation_failed`. Success → `202 Accepted` with the generation object in `queued`.

Header `Idempotency-Key` (≤ 255 chars):
- same key + same params → `202` with the generation **as it is now**, header `Idempotent-Replayed: true`. Nothing new created or billed; doesn't count against concurrency/daily limits.
- same key + different params → `409 idempotency_key_reused`.
- keys never expire and are scoped to the account.

### `GET /v1/generations/{id}`

Returns the generation object. Another account's id → `404 not_found`.

### `GET /v1/generations`

Newest first, cursor pagination: `limit` (1–100, default 20), `status`, `cursor` (from `next_cursor`). Response: `{object: "list", data: [...], has_more, next_cursor}`.

### `GET /v1/usage`

Per UTC day billing for the **calling key**. `start_date`/`end_date` (`YYYY-MM-DD`, UTC, inclusive; default last 30 days; max 92 days → `422 date_range_too_long`). A generation is billed on the UTC day it **completed**. `cost_usd` is an exact 9-decimal string. Days without usage are included with zeros. Response has `data[]` (date, generations, four token counts, cost_usd) and `total`.

## Generation object

| Field | Notes |
|---|---|
| `status` | `queued` → `processing` → `succeeded` \| `failed`. Final states are final. |
| `usage` | null until succeeded; then `input_text_tokens`, `input_image_tokens`, `output_tokens`, `reasoning_tokens`, `total_tokens` |
| `rates` | rates this generation is billed at (per 1M tokens), fixed at creation |
| `image_url` | signed PNG URL once succeeded; stops working at `image_url_expires_at` (24h after completion). Re-fetching the generation gives a fresh URL with the same expiry. |
| `error` | null unless failed: `{code, message}`; `generation_failed` or `internal_error`. Failed generations are never billed. |
| `created_at`, `started_at`, `completed_at` | lifecycle timestamps |

Typical timing: a few seconds queued, 5–30 s processing (`[slow]`: 60–90 s).

## Errors

Shape: `{"error": {"type", "code", "message", "param"?, "errors"?}}`. Branch on `code`.

| Code | Status | Retry? | PixelPilot handling |
|---|---|---|---|
| `invalid_api_key` | 401 | no | Configuration error; generation marked failed, logged, operator must fix the key |
| `validation_failed` | 422 | no | Shown to the user against the field in `param` |
| `content_policy_violation` | 400 | no | Nothing created or billed. Shown to the user; local record marked failed at $0 |
| `not_found` | 404 | no | |
| `idempotency_key_reused` | 409 | no | Indicates a bug (we never change params for a key); logged |
| `rate_limit_exceeded` | 429 | yes, after `Retry-After` | Submission job re-queued with delay |
| `concurrency_limit_exceeded` | 429 | yes, `Retry-After: 5` | Submission job re-queued with delay |
| `daily_limit_exceeded` | 429 | not today (UTC) | Marked failed at $0 with an explanation |
| `overloaded` | 503 | yes, `Retry-After: 2` | Retried with the same Idempotency-Key |
| `date_range_too_long` | 422 | no | Usage sync splits ranges into ≤ 92 days |

Network failures/timeouts on `POST /generations` are ambiguous (the generation may exist). They're retried with the **same** Idempotency-Key, which returns the existing generation instead of creating a second one.

## Limits (per account, across keys)

- 60 requests/minute → `429 rate_limit_exceeded` with `Retry-After`
- 3 generations queued or processing at once → `429 concurrency_limit_exceeded`
- 500 generations per UTC day → `429 daily_limit_exceeded`
- Every authenticated response carries `X-RateLimit-Limit` and `X-RateLimit-Remaining`.
- Back off, cap retries, give up after a few attempts. Retries count towards the per-minute limit.

## Token counting and pricing

- Input text: estimate `ceil(characters / 4)` (Unicode characters). The real count is within ±15% of that estimate.
- Input image: exactly 765 per reference image.
- Output: exact by quality and size (table above).
- Reasoning: only known after completion; within the quality's min/max.
- Cost = Σ tokens × rate / 1,000,000. Exact, never rounded (`0.373875000` for the documented worst case: high, 1536x1024, 400-char prompt, 2 references).

## Webhooks

POST to the key's webhook URL whenever a generation changes status.

| Event | When |
|---|---|
| `generation.processing` | left the queue |
| `generation.succeeded` | finished, with `usage` and `image_url` |
| `generation.failed` | failed, with `error` |
| `ping` | "Send test event" in the dashboard |

Body: `{"id": "evt_…", "object": "event", "type", "created_at", "data": {"object": <full generation at the time of the event>}}`.

Headers: `Pixelforge-Event-Id`, `Pixelforge-Event-Type`, `Pixelforge-Delivery-Attempt`, `Pixelforge-Signature: t=<unix>,v1=<hex>`.

Signature: hex HMAC-SHA256 of `"{t}.{raw body}"` keyed with the key's signing secret (`whsec_…`). Compare in constant time; reject old timestamps (we use ±300 s). Each attempt is signed with a fresh timestamp.

Delivery: any 2xx within 10 s counts. Otherwise retried after 10s, 30s, 2m, 10m, 15m (6 attempts total). Redirects aren't followed. Delivery is at least once and in any order (a retried `generation.processing` can land after `generation.succeeded`); events can arrive before the create request returns; delivery isn't guaranteed, so poll `GET /v1/generations/{id}` as a fallback.

Webhook URLs must be public (no localhost/private ranges); use a tunnel locally.

## Test keywords (case-insensitive, anywhere in the prompt; still counted as text tokens)

| Keyword | Behaviour |
|---|---|
| `[blocked]` | 400 `content_policy_violation` at submission |
| `[overloaded]` | 503 `overloaded` at submission |
| `[fail]` | accepted, then `generation.failed` |
| `[succeed]` | accepted, never fails at random |
| `[slow]` | 60–90 s processing |
| `[duplicate]` | every webhook delivered twice |

Without keywords there's still a small rate of random failures, 503s and duplicate deliveries.
