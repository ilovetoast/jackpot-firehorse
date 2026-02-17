# Storage Add-On Implementation Plan

## Executive Summary

Enable users who hit their storage limit to purchase add-on storage through the Billing section. Storage add-ons are recurring line items on their Stripe subscription, priced with a 30–40% markup over AWS S3 costs. This document outlines the full implementation plan, Stripe setup, and competitor-informed pricing.

---

## 1. Current State

- **Plans**: Free (100 MB), Starter (1 GB), Pro (~10 GB), Enterprise (~1 TB)
- **Enforcement**: `PlanService::enforceStorageLimit()` throws `PlanLimitExceededException` when upload would exceed limit
- **Error message**: "Adding this file would exceed your storage limit. Current usage: X MB, Plan limit: Y MB"
- **Billing**: Laravel Cashier + Stripe; single subscription item per tenant
- **No add-on storage**: Users must upgrade plan to get more storage

---

## 2. Cost Basis & Pricing

### 2.1 AWS S3 Storage Cost (Reference)

| Storage Class | Cost/GB/month | Notes |
|---------------|---------------|-------|
| S3 Standard   | $0.023        | First 50 TB |
| S3 Standard-IA | $0.0125      | Infrequent access |
| S3 One Zone-IA | $0.01        | Single AZ |

**Recommendation**: Use **$0.025/GB/month** as baseline (slightly above S3 Standard to cover requests, transfer, and overhead).

### 2.2 Markup & Retail Pricing

| Markup | Cost/GB | Retail/GB | 50 GB Add-on |
|--------|---------|-----------|--------------|
| 30%    | $0.025  | $0.0325   | $1.63/mo     |
| 35%    | $0.025  | $0.0338   | $1.69/mo     |
| 40%    | $0.025  | $0.035    | $1.75/mo     |

**Recommendation**: **35% markup** → ~$0.034/GB/month. Rounds to simple pricing.

### 2.3 Competitor Reference

| Provider | Add-on | Price | Per GB |
|---------|--------|-------|--------|
| Google One | 100 GB | $1.99/mo | ~$0.02 |
| Google One | 2 TB | $9.99/mo | ~$0.005 |
| Dropbox | 1 TB add-on | One-time or recurring | Varies |

**Recommendation**: Price add-ons at **$2–3 per 50 GB/month** to stay competitive while profitable. At 35% markup: 50 GB × $0.034 ≈ **$1.70/mo**; round to **$2/mo** for simplicity and margin.

### 2.4 Proposed Add-On Packages

| Package | Storage | Monthly Price | Per GB | Stripe Price Type |
|---------|---------|---------------|--------|-------------------|
| Small  | 50 GB   | $2.00         | $0.04  | Recurring         |
| Medium | 100 GB  | $3.50         | $0.035 | Recurring         |
| Large  | 250 GB  | $7.50         | $0.03  | Recurring         |
| X-Large| 500 GB  | $14.00        | $0.028 | Recurring         |

**Config location**: `config/storage_addons.php` (see Section 5).

---

## 3. Stripe Setup

### 3.1 Stripe Recommendation: Subscription Items (Add-On Line Items)

**Approach**: Add a **second subscription item** to the existing subscription for storage add-on.

**Why this works:**
- Single invoice with multiple line items (base plan + storage add-on)
- Proration handled by Stripe when adding/removing
- Same billing interval as main subscription (monthly)
- Clear line items on invoice: "Starter Plan" + "Storage Add-on: 50 GB"

### 3.2 Stripe Dashboard Setup

1. **Create Product**: "Storage Add-on"
   - Type: Service
   - Description: "Additional storage for your Jackpot workspace"

2. **Create Prices** (one per package):
   - `price_storage_50gb`: $2.00/month, recurring
   - `price_storage_100gb`: $3.50/month, recurring
   - `price_storage_250gb`: $7.50/month, recurring
   - `price_storage_500gb`: $14.00/month, recurring

3. **Add to subscription**: Use Stripe API `SubscriptionItem::create()`:
   ```php
   $subscription->items()->create([
       'price' => 'price_storage_50gb',
       'quantity' => 1,
   ]);
   ```

### 3.3 Billing Behavior

| Action | Stripe Behavior |
|--------|-----------------|
| Add 50 GB add-on | Prorated charge for remainder of billing period; appears on next invoice |
| Remove add-on | Prorated credit; removal at period end or immediately |
| Upgrade 50 GB → 100 GB | Swap subscription item; prorated difference |

### 3.4 Laravel Cashier Consideration

Cashier's `subscription('default')` typically tracks one price. For multiple items:

- **Option A**: Use Cashier's `subscription()->items` to manage multiple items (Cashier 15+ supports this)
- **Option B**: Use Stripe API directly for add-on items and store `storage_addon_price_id` on tenant
- **Option C**: Store add-on in `tenant.settings` or `tenant_metadata` and sync with Stripe subscription items

**Recommendation**: Use Stripe API directly for add-on management; Cashier for main subscription. Store `storage_addon_mb` and `storage_addon_stripe_price_id` on tenant (or in a `tenant_billing_addons` table).

---

## 4. Data Model Changes

### 4.1 Option A: Tenant Columns (Simplest)

```php
// Migration
$table->unsignedInteger('storage_addon_mb')->default(0);
$table->string('storage_addon_stripe_price_id')->nullable();
$table->string('storage_addon_stripe_subscription_item_id')->nullable();
```

### 4.2 Option B: Dedicated Table (More Flexible)

```sql
CREATE TABLE tenant_storage_addons (
    id BIGINT PRIMARY KEY,
    tenant_id BIGINT NOT NULL,
    storage_mb INT NOT NULL,
    stripe_price_id VARCHAR(255) NOT NULL,
    stripe_subscription_item_id VARCHAR(255) NOT NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

**Recommendation**: Option A for v1; migrate to Option B if you add more add-on types (e.g., AI credits).

---

## 5. Config: Centralized Storage Pricing

Create `config/storage_addons.php`:

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | S3 Cost Basis (per GB per month)
    |--------------------------------------------------------------------------
    | AWS S3 Standard: ~$0.023/GB. Use slightly higher to account for
    | requests, transfer, and operational overhead.
    */
    's3_cost_per_gb_month' => env('STORAGE_S3_COST_PER_GB', 0.025),

    /*
    |--------------------------------------------------------------------------
    | Markup (e.g., 1.35 = 35% markup)
    |--------------------------------------------------------------------------
    */
    'markup_multiplier' => env('STORAGE_MARKUP_MULTIPLIER', 1.35),

    /*
    |--------------------------------------------------------------------------
    | Add-On Packages
    |--------------------------------------------------------------------------
    | Stripe price IDs from Dashboard. Prices should be recurring monthly.
    */
    'packages' => [
        [
            'id' => 'storage_50gb',
            'storage_mb' => 51200, // 50 GB
            'stripe_price_id' => env('STRIPE_PRICE_STORAGE_50GB'),
            'monthly_price' => 2.00,
            'label' => '50 GB',
        ],
        [
            'id' => 'storage_100gb',
            'storage_mb' => 102400,
            'stripe_price_id' => env('STRIPE_PRICE_STORAGE_100GB'),
            'monthly_price' => 3.50,
            'label' => '100 GB',
        ],
        [
            'id' => 'storage_250gb',
            'storage_mb' => 256000,
            'stripe_price_id' => env('STRIPE_PRICE_STORAGE_250GB'),
            'monthly_price' => 7.50,
            'label' => '250 GB',
        ],
        [
            'id' => 'storage_500gb',
            'storage_mb' => 512000,
            'stripe_price_id' => env('STRIPE_PRICE_STORAGE_500GB'),
            'monthly_price' => 14.00,
            'label' => '500 GB',
        ],
    ],
];
```

---

## 6. PlanService Changes

### 6.1 Effective Storage Limit

```php
// PlanService::getMaxStorage()
$planLimit = $limits['max_storage_mb'] * 1024 * 1024;
$addonMb = $tenant->storage_addon_mb ?? 0;
return $planLimit + ($addonMb * 1024 * 1024);
```

### 6.2 Storage Info Response

Include add-on in `getStorageInfo()`:

```php
'max_storage_mb' => round(($planLimit + $addonMb) / 1024 / 1024, 2),
'base_plan_storage_mb' => ...,
'addon_storage_mb' => $addonMb,
'has_storage_addon' => $addonMb > 0,
```

---

## 7. Billing UI Flow

### 7.1 Billing Page: "Add Storage" Section

**When to show:**
- User has active subscription (Stripe or comped)
- Plan has finite storage (not 999999)
- Show when at or near limit, or always for discoverability

**UI:**
- Current: "Storage: 2,069 MB / 1,024 MB" (over limit)
- CTA: "Add storage to continue uploading"
- List add-on packages: "50 GB for $2/mo", "100 GB for $3.50/mo", etc.
- "Add 50 GB" button → Stripe flow

### 7.2 Upload Error: Link to Billing

When storage limit exceeded:
- Error: "Adding this file would exceed your storage limit. Current usage: 2,069 MB, Plan limit: 1,024 MB"
- Add link: "Add storage" → `/app/billing` (or scroll to storage add-on section)

---

## 8. Stripe Integration Flow

### 8.1 Add Storage Add-On

1. User clicks "Add 50 GB" on Billing page
2. `POST /app/billing/storage-addon` with `package_id` or `stripe_price_id`
3. Backend:
   - Verify tenant has active subscription
   - If no subscription (comped/free): optionally create checkout for add-on-only, or require plan first
   - Get subscription: `$tenant->subscription('default')`
   - Add subscription item: `Stripe\SubscriptionItem::create(['subscription' => $sub->stripe_id, 'price' => $priceId])`
   - Store `storage_addon_mb`, `storage_addon_stripe_price_id`, `storage_addon_stripe_subscription_item_id` on tenant
4. Redirect to success or show confirmation

### 8.2 Remove Storage Add-On

1. User clicks "Remove add-on" on Billing page
2. `DELETE /app/billing/storage-addon`
3. Backend: `Stripe\SubscriptionItem::update($itemId, ['proration_behavior' => 'create_prorations'])->delete()` or schedule for period end
4. Clear tenant add-on columns

### 8.3 Change Add-On (e.g., 50 GB → 100 GB)

1. Swap subscription item: delete old, add new (or update price)
2. Stripe prorates automatically

---

## 9. Webhook Handling

- `customer.subscription.updated`: Sync `storage_addon_*` from subscription items if changed externally
- `customer.subscription.deleted`: Clear add-on when subscription ends
- `invoice.paid`: No special handling; invoice will include add-on line item

---

## 10. Implementation Phases

### Phase 1: Config & Data Model
- [ ] Create `config/storage_addons.php`
- [ ] Migration: add `storage_addon_mb`, `storage_addon_stripe_price_id`, `storage_addon_stripe_subscription_item_id` to tenants
- [ ] Create Stripe products/prices in Dashboard
- [ ] Add env vars for Stripe price IDs

### Phase 2: PlanService
- [ ] Update `getMaxStorage()` to include add-on
- [ ] Update `getStorageInfo()` to return add-on info
- [ ] Ensure `canAddFile()` and `enforceStorageLimit()` use effective limit

### Phase 3: Billing Backend
- [ ] `BillingController::addStorageAddon(Request $request)`
- [ ] `BillingController::removeStorageAddon()`
- [ ] `BillingService::addStorageAddon(Tenant $tenant, string $priceId): void`
- [ ] `BillingService::removeStorageAddon(Tenant $tenant): void`
- [ ] Webhook: sync add-on state on subscription changes

### Phase 4: Billing UI
- [ ] Billing page: "Storage Add-On" section when plan has finite storage
- [ ] Show packages, "Add X GB" buttons
- [ ] Show current add-on if any, "Remove" or "Change" options
- [ ] Storage usage bar reflects base + add-on

### Phase 5: Upload Error UX
- [ ] In storage limit error (normalizer or UploadGate): add "Add storage" link to `/app/billing`
- [ ] Optional: pass `?highlight=storage` to scroll to add-on section

---

## 11. Stripe Best Practices Summary

| Practice | Recommendation |
|----------|----------------|
| **Add-on as line item** | Use Subscription Items API to add second price to subscription |
| **Proration** | Stripe prorates automatically when adding/removing items |
| **Billing interval** | Add-on must match subscription interval (monthly) |
| **Idempotency** | Use idempotency keys for add/remove to avoid duplicates |
| **Security** | Never accept price_id from client; validate server-side against config |
| **Invoices** | Add-on appears as separate line item on next invoice |

---

## 12. Revenue Example

- S3 cost: 50 GB × $0.025 = $1.25/mo
- Retail: $2.00/mo
- Margin: $0.75/mo (60% gross margin)
- At 100 tenants with 50 GB add-on: $200/mo revenue, ~$125 cost, ~$75 profit

---

## 13. Open Questions

1. **Comped/free tenants**: Allow add-on without base subscription? (Likely no — require paid plan.)
2. **Annual billing**: If subscription is annual, add-on should be annual too (match interval).
3. **Multiple add-ons**: Can user stack 50 GB + 50 GB? Recommend single add-on, upgrade to larger package to change.
4. **Downgrade add-on**: Allow 100 GB → 50 GB? Stripe supports it with proration.

---

## Appendix A: Stripe API Snippets

```php
// Add subscription item
$stripe = new \Stripe\StripeClient(config('services.stripe.secret'));
$item = $stripe->subscriptionItems->create([
    'subscription' => $tenant->subscription('default')->stripe_id,
    'price' => 'price_storage_50gb',
    'quantity' => 1,
]);
// Store $item->id as storage_addon_stripe_subscription_item_id

// Remove subscription item
$stripe->subscriptionItems->delete($tenant->storage_addon_stripe_subscription_item_id);
```

---

## Appendix B: Competitor Add-On Patterns

- **Dropbox**: 1 TB add-on, one-time or recurring
- **Google**: Tiered (100 GB, 200 GB, 2 TB, etc.) — user picks tier
- **Box**: Per-user storage add-ons
- **Best practice**: Offer 2–4 fixed packages; avoid metered/usage-based for v1 (simpler)
