# Pricing

Users pay what Pixelforge charges us plus 25%. Pixelforge never returns a price, only token usage and rates, so PixelPilot works the price out itself.

```
provider cost   = (text × text rate + image × image rate + output × output rate + reasoning × reasoning rate) / 1,000,000
customer charge = provider cost × 1.25
```

Everything lives in `App\Services\Pricing\PricingService`. Nothing else does pricing maths; the frontend only formats strings the server sends.

## Estimate (before submitting)

The Generate page asks the server for an estimate (`GET /generate/estimate`). It sends the prompt's **character count**, not the prompt, so prompts never land in URLs or access logs. The server reads the current rates and token rules from `GET /v1/pricing` (cached for 5 minutes, with the last good copy kept for a day in case the endpoint is down) and applies the documented rules:

| Token type | Rule | Estimate |
|---|---|---|
| Input text | `ceil(chars / 4)`, real count within ±15% | `floor(est × 0.85)` … `ceil(est × 1.15)` |
| Input image | exactly 765 per reference image | exact |
| Output | exact per quality × size | exact |
| Reasoning | only known afterwards, bounded by quality | quality min … max |

The text range is rounded **outwards** (floor the minimum, ceil the maximum) so the true count is always inside it. The 15% variance is kept as the decimal string `"0.15"` and multiplied with BCMath, so `20 × 1.15` is exactly 23, not 22.999….

The result is a range. Pixelforge's worked example (high, 1536×1024, 400 characters, 2 references) gives a maximum provider cost of **$0.373875**, which our estimate reproduces exactly (`tests/Unit/PricingServiceTest.php`). The user sees $0.357781 – $0.467344 for that request.

The estimate and the rates it used are stored on the generation (`estimated_*`, `estimate_rates`, `estimated_at`) so it can be shown later next to the actual charge.

## Actual charge (after success)

When Pixelforge reports a generation as `succeeded` (webhook, create response or poll), `GenerationSynchronizer` locks the generation row and `ChargeRecorder`:

1. takes the usage from Pixelforge's generation object;
2. takes the **rates from that same generation object**: Pixelforge fixes them when the generation is created, so a later price change doesn't affect it;
3. computes the provider cost and customer charge;
4. writes them to the generation and inserts one `generation_charges` row holding the token counts, the four rates, the markup, both amounts and the UTC billing day.

The estimate is never used for billing.

A failed generation is recorded with `actual_cost = 0` and `actual_charge = 0` and no charge row. So is a request Pixelforge rejected (content policy, daily quota) or one we gave up submitting.

## Precision and rounding policy

- **No floats.** Amounts are decimal strings handled with BCMath (`App\Support\Money`). It rejects anything that isn't a plain decimal string, so a float can't sneak in.
- **No rounding in stored amounts.** At the documented rates a provider cost has at most 9 decimal places (one token at $12.50/1M is $0.0000125) and the 25% markup adds at most 2. Amounts are stored as `NUMERIC(24,12)`, which holds every one of them exactly with room to spare. `Money::normalize()` throws instead of truncating if an amount ever needs more than 12 places.
- **Rounding only for display.** The UI trims trailing zeros (`$0.3818875`). Exact values are always available on the generation detail page.
- **Sums are done by PostgreSQL** on `NUMERIC` columns, which is exact.

Pixelforge itself bills in fractions of a cent and never rounds, so we don't either. If a real payment processor were involved, the place to round (per invoice, not per generation) would be at settlement, recorded as a separate adjustment.

## Invariants and where they are enforced

| Rule | Enforced by |
|---|---|
| A generation is charged at most once | unique index on `generation_charges.generation_id`; row lock + forward-only status in `GenerationSynchronizer` |
| Failed generations are free | `CHECK (status <> 'failed' OR (actual_charge = 0 AND actual_cost = 0))` |
| Only finished generations have actual amounts | `CHECK` on `generations` |
| Duplicate success events don't charge again | succeeded is final; a repeat only refreshes the image URL |
| Succeeded can't go back to processing/queued/failed | `GenerationStatus::canTransitionTo()` and a Postgres trigger that rejects any status change once final |
| A recorded charge never changes | Postgres trigger on `generations.actual_*`; `generation_charges` rejects UPDATE and DELETE |
| Cost matches the reported usage | `CHECK (provider_cost × 1,000,000 = Σ tokens × rate)` on `generation_charges` |
| Charge is exactly cost × markup | `CHECK (customer_charge = provider_cost × markup)` |
| Reproducible from stored data | token counts, rates and markup are stored on every charge row; the two CHECKs prove the amounts follow from them |

The database constraints mean a bug in application code produces a failed transaction (and a retried job), not a wrong number.

## Examples

| Scenario | Usage (text / image / output / reasoning) | Provider cost | Charge |
|---|---|---|---|
| Docs example | 12 / 765 / 6,450 / 3,184 | $0.30551 | $0.3818875 |
| Docs usage day | 41 / 0 / 3,300 / 2,950 | $0.16908 | $0.21135 |
| One text token | 1 / 0 / 0 / 0 | $0.000005 | $0.00000625 |
| Three reasoning tokens | 0 / 0 / 0 / 3 | $0.0000375 | $0.000046875 |
| Failed | — | $0 | $0 |
