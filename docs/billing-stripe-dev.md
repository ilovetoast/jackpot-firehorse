# Stripe billing (local / dev)

## Test mode

Use **Stripe test mode** for local and non-production environments:

- Dashboard: toggle **Test mode** (or use a test-only Stripe account).
- API keys: `sk_test_...` and `pk_test_...` (set in `.env` as `STRIPE_SECRET` / client-side publishable key where your frontend reads it).
- Never commit live keys (`sk_live_...`) or live Price IDs as defaults.

`STRIPE_KEY` / `STRIPE_SECRET` are read from `config/services.php` (Laravel `services.stripe`).

## Where Price IDs live

Central map: **`config/billing_stripe.php`**, key `stripe_prices`:

| Group | Config path | Env vars (preferred) |
|-------|-------------|----------------------|
| Plans | `stripe_prices.plans.*` | `STRIPE_PRICE_STARTER_MONTHLY`, `STRIPE_PRICE_PRO_MONTHLY`, `STRIPE_PRICE_BUSINESS_MONTHLY` |
| Add-ons | `stripe_prices.addons.*` | `STRIPE_PRICE_STORAGE_*_MONTHLY`, `STRIPE_PRICE_AI_CREDITS_*_MONTHLY`, `STRIPE_PRICE_CREATOR_*_MONTHLY` |

The file ships with **test** Price ID defaults so a fresh clone works against a matching Stripe test account. Override via `.env` without editing PHP.

**Legacy env names** are still supported as fallbacks when a `*_MONTHLY` variable is unset (e.g. `STRIPE_PRICE_STARTER`, `STRIPE_PRICE_CREDITS_500`, `STRIPE_PRICE_STORAGE_100GB`).

## Plan / add-on mapping (app source of truth)

- **Limits and entitlements** (storage included, AI credits included, brands, users, creator seats, modules) remain in:
  - `config/plans.php`
  - `config/storage_addons.php`
  - `config/ai_credits.php`
  - `config/creator_addon.php`
- **Stripe Price IDs** only identify which recurring price to attach at checkout or on subscription item updates. They are **not** the source of truth for feature limits.

Paid plan rows in `config/plans.php` and add-on packages read Price IDs via `config('billing_stripe.stripe_prices...')`.

## Resolving Price IDs in code

Use **`App\Services\Billing\StripePriceMap`**:

- `priceIdForPlan('starter'|'pro'|'business')`
- `priceIdForAddon('storage_100gb'|...|'ai_credits_500'|...)`
- `priceIdForAiCreditsPackId('credits_500'|...)` — maps `config/ai_credits.php` pack ids to Stripe
- `resolveKeyForPriceId($stripePriceId)` — reverse map for webhooks (`kind` = `plan` | `addon`, `key` = internal key)
- `assertUniquePriceIds()` — guards against two keys sharing one Price ID (used in tests)

## Lookup keys

This project maps by **Price ID** (`price_...`). Stripe **lookup keys** are not included in typical CSV exports; do not assume they exist in config.

**TODO:** add an Artisan command (e.g. `stripe:verify-prices`) that lists Prices from the Stripe API (`lookup_key`, metadata) and diffs against `config/billing_stripe.php`.

## Live mode

Production and staging that bill real customers need **live** Price IDs (and live API keys). Set the same `STRIPE_PRICE_*` env variables to live `price_...` values for that environment. Do not reuse test Price IDs in live mode.

## Next steps (checkout / subscriptions)

Existing billing UI and `BillingController` already consume `stripe_price_id` from plan and add-on config (which now resolves through `billing_stripe`). No checkout changes were required for this wiring.

Follow-up work when you extend flows:

- Ensure new checkout/session creation uses `config('plans.*.stripe_price_id')` or `StripePriceMap` so all paths stay consistent.
- In Stripe webhooks, use `StripePriceMap::resolveKeyForPriceId()` to normalize subscription line items to internal plan/add-on keys before updating tenant state.
