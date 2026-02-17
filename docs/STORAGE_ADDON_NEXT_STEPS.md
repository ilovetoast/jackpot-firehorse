# Storage Add-On — Next Steps

Stripe is connected. Follow these steps to enable the add storage feature.

---

## Phase 1: Stripe Dashboard Setup

### 1.1 Create Storage Add-On Product in Stripe

1. Go to **Stripe Dashboard** → **Products** → **Add product**
2. **Name**: `Storage Add-on`
3. **Description**: `Additional storage for your Jackpot workspace`
4. **Type**: Service

### 1.2 Create Recurring Prices

Create 4 monthly recurring prices under the Storage Add-on product:

| Package | Storage | Price | Stripe Price ID (suggested) |
|---------|---------|-------|-----------------------------|
| Small   | 50 GB   | $2.00/mo  | `price_storage_50gb`  |
| Medium  | 100 GB  | $3.50/mo  | `price_storage_100gb` |
| Large   | 250 GB  | $7.50/mo  | `price_storage_250gb` |
| X-Large | 500 GB  | $14.00/mo | `price_storage_500gb` |

**Important**: Copy the actual Price IDs from Stripe (e.g. `price_1Slzx...`) — you'll need them for `.env`.

### 1.3 Add to `.env`

```env
STRIPE_PRICE_STORAGE_50GB=price_xxx
STRIPE_PRICE_STORAGE_100GB=price_xxx
STRIPE_PRICE_STORAGE_250GB=price_xxx
STRIPE_PRICE_STORAGE_500GB=price_xxx
```

---

## Phase 2: Config & Database

### 2.1 Create `config/storage_addons.php`

See `docs/STORAGE_ADDON_IMPLEMENTATION_PLAN.md` Section 5 for the full config template. Key structure:

```php
'packages' => [
    ['id' => 'storage_50gb', 'storage_mb' => 51200, 'stripe_price_id' => env('STRIPE_PRICE_STORAGE_50GB'), ...],
    // ...
],
```

### 2.2 Migration: Add Tenant Columns

```php
$table->unsignedInteger('storage_addon_mb')->default(0);
$table->string('storage_addon_stripe_price_id')->nullable();
$table->string('storage_addon_stripe_subscription_item_id')->nullable();
```

---

## Phase 3: PlanService Updates

- Update `getMaxStorage()` to include `$tenant->storage_addon_mb`
- Update `getStorageInfo()` to return `addon_storage_mb`, `has_storage_addon`
- Ensure `enforceStorageLimit()` uses the effective (base + addon) limit

---

## Phase 4: Billing Backend

### 4.1 Routes (already in `web.php`)

- `POST /app/billing/storage-addon` — add add-on
- `DELETE /app/billing/storage-addon` — remove add-on

### 4.2 BillingController Methods

- `addStorageAddon(Request $request)` — validate package, add Stripe subscription item, update tenant
- `removeStorageAddon()` — delete Stripe subscription item, clear tenant columns

### 4.3 Stripe API Usage

```php
// Add
$item = $stripe->subscriptionItems->create([
    'subscription' => $tenant->subscription('default')->stripe_id,
    'price' => $priceId,
    'quantity' => 1,
]);

// Remove
$stripe->subscriptionItems->delete($tenant->storage_addon_stripe_subscription_item_id);
```

---

## Phase 5: Billing UI

- Add "Storage Add-On" section to Billing page when plan has finite storage
- Show packages: "50 GB for $2/mo", etc.
- "Add 50 GB" button → POST to backend
- If add-on exists: show "Remove" or "Change" options
- Storage usage bar reflects base + add-on

---

## Phase 6: Upload Error UX

- When storage limit exceeded: add "Add storage" link → `/app/billing` (or `?highlight=storage`)

---

## Quick Reference: Implementation Plan

Full details in `docs/STORAGE_ADDON_IMPLEMENTATION_PLAN.md`:
- Cost basis & pricing (Section 2)
- Webhook handling (Section 9)
- Stripe API snippets (Appendix A)
