# Subscription Upgrade/Downgrade Guide

## Overview

This guide explains how subscription upgrades and downgrades are handled in the Jackpot application, including how Stripe automatically handles proration.

## How It Works

### Stripe Automatic Proration

**Yes, Stripe handles proration automatically!** When you use Laravel Cashier's `swap()` method, Stripe automatically:

1. **Calculates proration** based on:
   - Time remaining in the current billing period
   - Price difference between old and new plans
   - Current subscription status

2. **For Upgrades:**
   - Customer is charged immediately for the prorated difference
   - The charge appears on their next invoice
   - Example: If they upgrade mid-month, they pay the difference for the remaining days

3. **For Downgrades:**
   - Customer receives a prorated credit
   - Credit applies to their next invoice
   - Downgrade takes effect at the end of the current billing period (or immediately if configured)

### Best Practices

#### ✅ DO: Use `updateSubscription()` for Plan Changes

```php
// This is the correct way - handles proration automatically
$billingService->updateSubscription($tenant, $newPriceId, $oldPlanId, $newPlanId);
```

**What happens:**
- Uses `swap()` which automatically prorates
- Cancels old subscription item
- Creates new subscription item
- Stripe calculates and applies proration

#### ❌ DON'T: Create New Checkout Sessions for Existing Subscriptions

```php
// This is WRONG - creates duplicate subscriptions
$billingService->createCheckoutSession($tenant, $newPriceId);
```

**Problems:**
- Creates a second subscription
- Customer gets charged twice
- No automatic proration
- Confusing billing situation

### Current Implementation

The application now automatically handles this:

1. **`subscribe()` method** checks if a subscription exists:
   - If subscription exists → redirects to `updateSubscription()` (proper proration)
   - If no subscription → creates new checkout session

2. **`updateSubscription()` method** uses `swap()`:
   - Automatically prorates charges
   - Handles upgrades and downgrades correctly
   - Logs activity for audit trail

## Code Flow

### Scenario: User Upgrades from Starter to Pro

1. User clicks "Upgrade to Pro" on billing page
2. Frontend calls `POST /app/billing/subscribe` with `price_id` for Pro
3. `BillingController::subscribe()` checks for existing subscription
4. Finds active Starter subscription
5. Automatically calls `updateSubscription()` instead of creating checkout
6. `BillingService::updateSubscription()`:
   - Gets current subscription
   - Calls `$tenant->subscription('default')->swap($newPriceId)`
   - Stripe automatically:
     - Calculates prorated amount (difference × remaining days)
     - Charges customer immediately
     - Updates subscription to Pro plan
7. Returns success message explaining proration

### Scenario: New User Subscribes

1. User clicks "Subscribe to Starter"
2. Frontend calls `POST /app/billing/subscribe` with `price_id` for Starter
3. `BillingController::subscribe()` checks for existing subscription
4. No subscription found
5. Creates Stripe Checkout session
6. User completes payment
7. Webhook creates subscription in database

## Proration Examples

### Example 1: Mid-Month Upgrade

- **Current Plan:** Starter ($10/month)
- **New Plan:** Pro ($30/month)
- **Days Remaining:** 15 days (half month)
- **Calculation:**
  - Daily rate difference: ($30 - $10) / 30 = $0.67/day
  - Prorated charge: $0.67 × 15 = $10.05
  - Customer charged: $10.05 immediately
  - Next invoice: Full $30 for next month

### Example 2: Mid-Month Downgrade

- **Current Plan:** Pro ($30/month)
- **New Plan:** Starter ($10/month)
- **Days Remaining:** 15 days
- **Calculation:**
  - Daily rate difference: ($10 - $30) / 30 = -$0.67/day
  - Prorated credit: -$0.67 × 15 = -$10.05
  - Customer receives: $10.05 credit
  - Next invoice: $10 - $10.05 credit = $0 (or applied to future)

## Disabling Proration (Not Recommended)

If you need to disable proration (not recommended for upgrades), you can modify the code:

```php
// In BillingService::updateSubscription()
$tenant->subscription('default')->noProrate()->swap($priceId);
```

**Warning:** This means:
- Upgrades: Customer pays full price immediately (no credit for unused time)
- Downgrades: Customer loses remaining paid time (no credit)

## Testing

### Test Upgrade Flow

1. Create a test tenant with Starter subscription
2. Wait for subscription to be active
3. Call `updateSubscription()` with Pro price ID
4. Check Stripe dashboard:
   - Should see prorated invoice item
   - Subscription should show Pro plan
   - Next invoice should show correct amount

### Test Downgrade Flow

1. Create a test tenant with Pro subscription
2. Wait for subscription to be active
3. Call `updateSubscription()` with Starter price ID
4. Check Stripe dashboard:
   - Should see prorated credit
   - Subscription should show Starter plan
   - Next invoice should show credit applied

## Webhook Handling

The application handles Stripe webhooks for subscription changes:

- `customer.subscription.updated` - Updates subscription in database
- `invoice.created` - Creates invoice record
- `invoice.paid` - Marks invoice as paid
- `invoice.payment_failed` - Handles failed payments

All webhook events are logged for audit purposes.

## Common Issues

### Issue: Duplicate Subscriptions

**Cause:** Using `createCheckoutSession()` when subscription already exists

**Solution:** Use `updateSubscription()` instead (now handled automatically)

### Issue: Customer Charged Twice

**Cause:** Created new subscription instead of updating existing one

**Solution:** Application now prevents this by checking for existing subscriptions

### Issue: Proration Not Working

**Cause:** Using `noProrate()` or incorrect API usage

**Solution:** Ensure using `swap()` without `noProrate()` (default behavior)

## References

- [Stripe Proration Documentation](https://docs.stripe.com/billing/subscriptions/prorations)
- [Laravel Cashier Documentation](https://laravel.com/docs/billing)
- [Stripe Subscription API](https://stripe.com/docs/api/subscriptions)
