# Stripe + Laravel Cashier Best Practices Guide

## 1. Plans Management: Database vs Config File

### Current Approach (Config File) ✅ Good for Static Plans
- **Pros:**
  - Simple and fast (no DB queries)
  - Version controlled
  - Easy to deploy changes
  - Good for plans that rarely change
  
- **Cons:**
  - Requires code deployment to change plans
  - No admin UI for plan management
  - Harder to A/B test pricing
  - Can't easily add custom plans per customer

### Recommended: Hybrid Approach 🎯
**For most SaaS applications, use a hybrid:**

1. **Keep base plans in config** (for defaults, limits, features)
2. **Store plan metadata in database** (for dynamic pricing, promotions, custom plans)
3. **Sync with Stripe** (verify prices exist, track changes)

### Implementation Strategy:

```php
// config/plans.php - Keep for defaults
return [
    'starter' => [
        'name' => 'Starter',
        'stripe_price_id' => env('STRIPE_PRICE_STARTER'),
        'limits' => [...],
        'features' => [...],
    ],
];

// Database table: plans
// - id, key, name, stripe_price_id, limits (JSON), features (JSON)
// - is_active, sort_order, created_at, updated_at
// - Allows admin to modify without code changes
```

**Best Practice:** Start with config file, migrate to database when you need:
- Frequent plan changes
- A/B testing pricing
- Custom enterprise plans
- Promotional pricing
- Regional pricing

---

## 2. Admin Stripe Management Page

### ✅ YES - You Should Have One

**Essential Features:**

1. **Stripe Connection Status**
   - ✅ You have this already
   - Test API keys
   - Show account ID

2. **Plan Sync Status** (You have this)
   - Verify all plans exist in Stripe
   - Show mismatches
   - Alert on missing prices

3. **Customer Management** (Add this)
   - List all tenants with Stripe IDs
   - View subscription status
   - Manual sync button
   - Link to Stripe customer dashboard

4. **Subscription Overview** (Add this)
   - Active subscriptions count
   - MRR (Monthly Recurring Revenue)
   - Churn rate
   - Revenue by plan

5. **Webhook Management** (Add this)
   - Recent webhook events
   - Failed webhooks
   - Retry failed webhooks
   - Webhook endpoint status

6. **Manual Actions** (Add this)
   - Create subscription manually
   - Cancel subscription
   - Refund invoice
   - Update payment method
   - Sync subscription from Stripe

---

## 3. Stripe Customer Portal Link

### ✅ YES - Highly Recommended

**Why:**
- Stripe handles PCI compliance
- Self-service for customers
- Reduces support burden
- Professional billing management

**Implementation:**

```php
// In BillingController
public function customerPortal(Request $request)
{
    $tenant = $this->getTenant($request);
    
    return $tenant->redirectToBillingPortal(
        route('billing') // Return URL
    );
}
```

**Add to billing page:**
- "Manage Billing" button → Opens Stripe Customer Portal
- Customers can:
  - Update payment method
  - View invoices
  - Update billing address
  - Cancel subscription
  - Download invoices

**Best Practice:** Always provide both:
1. Your custom billing page (for plan selection, upgrades)
2. Stripe Customer Portal link (for payment management)

---

## 4. Additional Best Practices

### Frontend (Customer-Facing)

1. **Clear Pricing Display**
   - ✅ You have this
   - Show savings (annual vs monthly)
   - Highlight popular plan
   - Show "Most Popular" badge

2. **Usage Indicators**
   - ✅ You have this
   - Progress bars for limits
   - Warnings at 80% usage
   - Upgrade prompts when limits reached

3. **Payment Method Management**
   - Show current payment method
   - Update payment method button
   - Expiry warnings
   - Failed payment notifications

4. **Invoice History**
   - ✅ You have this
   - Download PDFs
   - Email receipts
   - Tax information

5. **Subscription Status**
   - Clear status indicators
   - Grace period warnings
   - Cancellation date
   - Reactivation option

### Backend/Admin

1. **Webhook Reliability**
   - ✅ You have error handling
   - Add webhook retry queue
   - Log all webhook events
   - Alert on failures

2. **Subscription Sync**
   - Periodic sync job (daily)
   - Manual sync endpoint
   - Detect discrepancies
   - Auto-fix common issues

3. **Revenue Analytics**
   - MRR tracking
   - Churn analysis
   - Plan distribution
   - Revenue forecasting

4. **Customer Support Tools**
   - Quick customer lookup
   - Subscription history
   - Payment failure tracking
   - Manual intervention tools

5. **Audit Trail**
   - Log all subscription changes
   - Track who made changes
   - Reason for changes
   - Timestamps

6. **Testing & Safety**
   - Test mode toggle
   - Webhook replay
   - Dry-run mode
   - Rollback capability

---

## 5. Refund Management

### Industry Standard: **Hybrid Approach** 🎯

**Most SaaS applications use:**

1. **Stripe Dashboard for Refunds** (Primary)
   - Full refunds
   - Partial refunds
   - Refund reasons
   - Compliance tracking
   
   **Why:** Stripe handles all compliance, tax, and reporting

2. **Backend Integration for Automation** (Secondary)
   - Automated refunds (e.g., 30-day money-back)
   - Prorated refunds on downgrades
   - Refund webhooks
   - Internal tracking

### Implementation:

```php
// BillingService.php
public function refundInvoice(Tenant $tenant, string $invoiceId, ?int $amount = null): void
{
    try {
        $invoice = $tenant->findInvoice($invoiceId);
        
        if ($amount) {
            // Partial refund
            $tenant->refund($invoice->charge, $amount);
        } else {
            // Full refund
            $tenant->refund($invoice->charge);
        }
        
        // Log refund
        \Log::info('Refund processed', [
            'tenant_id' => $tenant->id,
            'invoice_id' => $invoiceId,
            'amount' => $amount,
        ]);
    } catch (\Exception $e) {
        throw new \RuntimeException('Refund failed: ' . $e->getMessage());
    }
}
```

### Best Practices for Refunds:

1. **Automated Refunds:**
   - 30-day money-back guarantee
   - Prorated refunds on immediate cancellations
   - Failed payment auto-refund (if applicable)

2. **Manual Refunds:**
   - Use Stripe Dashboard (recommended)
   - Or admin panel with proper permissions
   - Always log reason

3. **Refund Webhooks:**
   - Handle `charge.refunded` webhook
   - Update subscription status
   - Notify customer
   - Update internal records

4. **Compliance:**
   - Stripe handles tax reporting
   - Keep internal audit log
   - Document refund reasons
   - Follow local regulations

### Recommended Approach:

**For your SaaS:**
- ✅ Use Stripe Dashboard for manual refunds (primary)
- ✅ Add admin panel refund button (convenience)
- ✅ Handle refund webhooks (automation)
- ✅ Log all refunds (audit trail)

---

## Implementation Priority

### Phase 1 (Essential - Do Now)
1. ✅ Webhook error handling (Done)
2. ✅ Plan sync status (Done)
3. Add Stripe Customer Portal link
4. Add refund webhook handler
5. Improve admin Stripe status page

### Phase 2 (Important - Next Sprint)
1. Add customer management to admin
2. Add subscription overview/analytics
3. Add webhook event log viewer
4. Add manual sync functionality
5. Add usage warnings/alerts

### Phase 3 (Nice to Have)
1. Migrate plans to database (if needed)
2. Add revenue analytics dashboard
3. Add automated refund rules
4. Add A/B testing for pricing
5. Add regional pricing support

---

## Security Best Practices

1. **Webhook Verification**
   - ✅ You have this (Cashier handles it)
   - Verify webhook signatures
   - Use webhook secrets

2. **API Key Security**
   - Never expose keys in frontend
   - Use environment variables
   - Rotate keys regularly
   - Use different keys for test/live

3. **Access Control**
   - Admin-only for refunds
   - Audit all manual actions
   - Rate limit API calls
   - Monitor for abuse

4. **Data Protection**
   - Encrypt sensitive data
   - Don't store full card numbers
   - PCI compliance (Stripe handles this)
   - GDPR compliance for EU customers

---

## Monitoring & Alerts

1. **Webhook Failures**
   - Alert on consecutive failures
   - Monitor webhook latency
   - Track success rate

2. **Payment Failures**
   - Alert on failed payments
   - Track retry attempts
   - Monitor dunning emails

3. **Subscription Issues**
   - Alert on unexpected cancellations
   - Monitor churn rate
   - Track upgrade/downgrade patterns

4. **Revenue Metrics**
   - Daily MRR tracking
   - Revenue alerts (significant changes)
   - Plan distribution changes

---

## Recommended Tools & Integrations

1. **Analytics:**
   - Stripe Dashboard (built-in)
   - Custom dashboard (your admin panel)
   - Third-party: Baremetrics, ChartMogul

2. **Monitoring:**
   - Laravel Logging (you have this)
   - Sentry (error tracking)
   - Stripe webhook logs

3. **Testing:**
   - Stripe Test Mode
   - Webhook testing tools
   - Stripe CLI (you're using this ✅)

---

## Summary

**Your Current Setup:**
- ✅ Good webhook handling
- ✅ Plan sync status
- ✅ Basic admin Stripe page
- ✅ Customer billing page

**Recommended Additions:**
1. Stripe Customer Portal link
2. Enhanced admin management page
3. Refund webhook handling
4. Customer/subscription management
5. Revenue analytics

**Refund Strategy:**
- Use Stripe Dashboard for manual refunds (primary)
- Add backend refund method for automation
- Handle refund webhooks
- Keep audit logs

This hybrid approach gives you flexibility while maintaining security and compliance.
