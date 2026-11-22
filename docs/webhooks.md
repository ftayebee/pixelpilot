# Webhooks

Pixelforge tells us about status changes by POSTing events to `POST /webhooks/pixelforge`. Delivery is at least once and in any order, can arrive before our create request returns, and isn't guaranteed. This page explains how PixelPilot copes with each of those.

## Request path

```
Pixelforge ──POST──▶ VerifyPixelforgeSignature ──▶ PixelforgeWebhookController ──▶ WebhookInbox
                        (400 if invalid)             (204, or 422 if malformed)       │ INSERT … ON CONFLICT DO NOTHING
                                                                                      ▼
                                                                         queue: ProcessPixelforgeEvent
                                                                                      │
                                                                                      ▼
                                                     WebhookProcessor ──▶ GenerationSynchronizer ──▶ ChargeRecorder
                                                     (locks event row)    (locks generation row)     (unique charge row)
```

The route lives in `routes/webhooks.php`, loaded **outside** the `web` middleware group: no session, no cookies, no CSRF. The signature is what authenticates the request.

## 1. Signature (`PixelforgeWebhookVerifier`)

- Header `Pixelforge-Signature: t=<unix>,v1=<hex>`.
- Expected value: `hash_hmac('sha256', "{t}.{raw body}", PIXELFORGE_WEBHOOK_SECRET)`, compared with `hash_equals()`.
- The raw body (`$request->getContent()`) is used as-is. It's never decoded and re-encoded before verification.
- `t` must be within **300 seconds** of our clock, in either direction (replays and badly skewed clocks are rejected).
- Missing header, missing `t`, missing or malformed `v1`, wrong secret, edited body: `400`.
- If `PIXELFORGE_WEBHOOK_SECRET` isn't set, **every** webhook is rejected (fail closed).

## 2. Storage (`WebhookInbox`)

- The payload must be a JSON `event` with an `evt_…` id and a type; `generation.*` events must carry a generation object. Otherwise `422`. A signed but malformed event is a provider bug, so we log it loudly.
- The event goes into `webhook_events` with `INSERT … ON CONFLICT (event_id) DO NOTHING`. The **unique index on `event_id`** decides whether an event is new, so two concurrent deliveries of the same event can't both insert.
- The exact bytes are stored (`payload`), along with type, Pixelforge generation id, delivery count and the `Pixelforge-Delivery-Attempt` header.
- A duplicate delivery only bumps `deliveries`. If the event hasn't been processed yet, it's queued again (harmless, see below).
- We answer `204` as soon as the row exists, for new and duplicate events alike. Queueing failures are logged but don't change the response: the event is already safe in the database.

## 3. Processing (`ProcessPixelforgeEvent` → `WebhookProcessor`)

Inside one transaction:

1. Lock the `webhook_events` row (`SELECT … FOR UPDATE`). If it's already `processed`/`ignored`, stop. Two workers handling the same event are serialised here, and the second one does nothing.
2. `ping` and unknown event types → `ignored`.
3. Find the generation by `provider_generation_id`. If the create response hasn't been saved yet, fall back to `metadata.pixelpilot_generation`, the UUID we send with every create request. We only trust it if the prompt matches too and the row isn't linked to a different Pixelforge id.
4. No match → throw, so the job retries with backoff (5s, 15s, 1m, 5m, 15m). After the last attempt the event is marked `unmatched` (kept, not lost); a later redelivery or the polling fallback still resolves the generation.
5. Hand the generation object to `GenerationSynchronizer`, then mark the event `processed`.

## 4. Applying the state (`GenerationSynchronizer`)

- Locks the generation row, so a webhook, a poll and the create response for the same generation never interleave.
- **Statuses only move forward**: `submitting → queued → processing → succeeded | failed`. A snapshot that would move backwards, or change a final status, is ignored and logged. That covers a retried `generation.processing` arriving after `generation.succeeded`, and a `succeeded` after `failed`.
- We go by the **status in the generation object**, not by delivery order or event timestamps: Pixelforge's states are monotonic, so "furthest along wins" is always right, and stale events can't override newer state.
- On the first transition to `succeeded`, `ChargeRecorder` writes the single `generation_charges` row (unique per generation) from the reported usage. A repeat `succeeded` can only refresh the image URL.
- On `failed`, the generation is recorded at $0.

Postgres backs this up: a trigger refuses to change a final status or a recorded charge, and `generation_charges` can't be updated or deleted.

## 5. When webhooks don't arrive

- `pixelforge:process-pending-webhooks` (every 5 minutes) re-queues events that were stored but never processed, e.g. after a queue outage.
- The polling fallback (`docs/architecture.md`) asks Pixelforge directly for any generation that's still pending, so a generation reaches its final state even if every delivery attempt failed.

## Responses

| Situation | Status |
|---|---|
| Valid, new event | 204 |
| Valid, duplicate event | 204 |
| Valid signature, malformed event | 422 |
| Bad/missing/expired signature, or no secret configured | 400 |

## Local development

Pixelforge only delivers to public URLs. Expose your local server with a tunnel and set the key's webhook URL in the Pixelforge dashboard:

```bash
php artisan serve
cloudflared tunnel --url http://localhost:8000   # or: ngrok http 8000 / expose share http://localhost:8000
# Webhook URL: https://<tunnel-host>/webhooks/pixelforge
```

Use a separate API key (and therefore webhook URL and secret) for local development and for production. The Pixelforge dashboard shows each delivery attempt and our response, and can resend events, which is handy for testing duplicates.
