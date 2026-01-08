# Stripe Integration FAQ - Quick Answers

## 1. Do I need to add plans into the database for modification?

**Short Answer:** Not immediately, but consider it if you need frequent changes.

**Current Setup:** ✅ Config file is fine for now
- Plans are in `config/plans.php`
- Good for static plans that rarely change
- Version controlled and easy to deploy

**When to Migrate to Database:**
- You need to change plans frequently without code deployment
- You want A/B testing for pricing
- You need custom enterprise plans
- You want promotional pricing that changes often

**Recommendation:** 
- Start with config file (current approach) ✅
- Migrate to database when you need dynamic plan management
- Use hybrid: config for defaults, database for overrides

---

## 2. Do we need a management page from Stripe info on the admin stripe page?

**Short Answer:** ✅ YES - You already have a good start, but add more features.

**What You Have:**
- ✅ Stripe connection status
- ✅ Plan sync status
- ✅ Basic subscription list

**What to Add:**
1. **Customer Management**
   - List all tenants with Stripe IDs
   - View subscription details
   - Manual sync button
   - Link to Stripe customer dashboard

2. **Subscription Management**
   - Active subscriptions overview
   - MRR (Monthly Recurring Revenue)
   - Churn tracking
   - Revenue by plan

3. **Webhook Management**
   - Recent webhook events log
   - Failed webhooks
   - Retry functionality
   - Webhook endpoint status

4. **Manual Actions**
   - Create subscription
   - Cancel subscription
   - Refund invoice
   - Update payment method
   - Sync from Stripe

**Priority:** High - This is essential for operations and support.

---

## 3. Should we add a link on the plan page to the billing page hosted by Stripe?

**Short Answer:** ✅ YES - Highly recommended!

**Implementation:**
- Added route: `/app/billing/portal`
- Opens Stripe Customer Portal
- Customers can:
  - Update payment method
  - View/download invoices
  - Update billing address
  - Cancel subscription
  - Manage billing details

**Why:**
- Stripe handles PCI compliance
- Reduces support burden
- Professional self-service
- Better customer experience

**Best Practice:**
- Keep your custom billing page (for plan selection)
- Add "Manage Billing" button → Opens Stripe Portal
- Use both: Custom page for upgrades, Portal for payment management

**Code Added:**
```php
// Route: /app/billing/portal
// Controller: BillingController::customerPortal()
// Service: BillingService::getCustomerPortalUrl()
```

---

## 4. What other best practices should be added in admin and frontend?

### Frontend (Customer-Facing) ✅ Most Done

**Already Have:**
- ✅ Pricing page
- ✅ Usage indicators
- ✅ Invoice history
- ✅ Subscription status

**Add:**
1. **Payment Method Management**
   - Show current card
   - Update payment method button
   - Expiry warnings
   - Failed payment alerts

2. **Stripe Customer Portal Link**
   - "Manage Billing" button
   - Opens Stripe-hosted portal
   - Self-service options

3. **Better Status Indicators**
   - Grace period warnings
   - Cancellation date display
   - Reactivation option
   - Payment failure notifications

### Backend/Admin

**Already Have:**
- ✅ Webhook error handling
- ✅ Plan sync status
- ✅ Basic Stripe status page

**Add:**
1. **Enhanced Admin Dashboard**
   - Customer management
   - Subscription overview
   - Revenue analytics
   - Webhook event log

2. **Manual Operations**
   - Create/cancel subscriptions
   - Refund invoices
   - Sync subscriptions
   - Update payment methods

3. **Monitoring & Alerts**
   - Webhook failure alerts
   - Payment failure tracking
   - Churn monitoring
   - Revenue alerts

4. **Audit Trail**
   - Log all changes
   - Track who made changes
   - Reason for changes
   - Timestamps

**Priority:**
- Phase 1: Customer Portal link, refund handling
- Phase 2: Enhanced admin dashboard
- Phase 3: Analytics and monitoring

---

## 5. Do SaaS Laravel Cashier Stripe setups manage refunds via backend or Stripe?

**Short Answer:** **Hybrid Approach** - Both, but Stripe Dashboard is primary.

### Industry Standard Approach:

**1. Stripe Dashboard (Primary) ✅ Recommended**
- Use for manual refunds
- Full compliance handling
- Tax reporting
- Professional interface
- Audit trail in Stripe

**Why Primary:**
- Stripe handles all compliance
- Better for accounting
- Professional appearance
- Less code to maintain

**2. Backend Integration (Secondary) ✅ Added**
- Automated refunds (e.g., 30-day guarantee)
- Prorated refunds on downgrades
- Refund webhooks
- Internal tracking

**Implementation Added:**
- `BillingService::refundInvoice()` - Backend refund method
- `WebhookController::handleChargeRefunded()` - Refund webhook handler
- Logging for audit trail

### Best Practice Workflow:

**Manual Refunds:**
1. Support team uses Stripe Dashboard (primary)
2. Or use admin panel refund button (convenience)
3. Webhook automatically updates records

**Automated Refunds:**
1. Backend processes refund (e.g., money-back guarantee)
2. Webhook confirms and updates records
3. Customer notified automatically

**Refund Webhooks:**
- Always handle `charge.refunded` webhook
- Update subscription status if needed
- Log for audit trail
- Notify customer

### Your Setup:

**✅ Recommended Configuration:**
- Use Stripe Dashboard for manual refunds (primary)
- Backend method available for automation
- Webhook handler logs all refunds
- Admin can refund via panel if needed

**Code Added:**
```php
// Backend refund method
BillingService::refundInvoice($tenant, $invoiceId, $amount, $reason)

// Refund webhook handler
WebhookController::handleChargeRefunded($payload)
```

---

## Summary of Changes Made

### ✅ Added Features:

1. **Stripe Customer Portal**
   - Route: `/app/billing/portal`
   - Method: `BillingController::customerPortal()`
   - Service: `BillingService::getCustomerPortalUrl()`

2. **Refund Management**
   - Backend refund method: `BillingService::refundInvoice()`
   - Refund webhook handler: `WebhookController::handleChargeRefunded()`
   - Audit logging

3. **Documentation**
   - `STRIPE_BEST_PRACTICES.md` - Comprehensive guide
   - `STRIPE_FAQ.md` - This file

### 📋 Next Steps (Recommended):

1. **Immediate:**
   - Add "Manage Billing" button to billing page
   - Test Customer Portal link
   - Test refund webhook

2. **Short Term:**
   - Enhance admin Stripe status page
   - Add customer management
   - Add subscription overview

3. **Long Term:**
   - Consider migrating plans to database (if needed)
   - Add revenue analytics
   - Add automated refund rules

---

## Quick Reference

**Routes Added:**
- `GET /app/billing/portal` - Stripe Customer Portal

**Methods Added:**
- `BillingService::getCustomerPortalUrl()`
- `BillingService::refundInvoice()`
- `BillingController::customerPortal()`
- `WebhookController::handleChargeRefunded()`

**Best Practices:**
- ✅ Config file for plans (current approach)
- ✅ Admin management page (enhance existing)
- ✅ Stripe Customer Portal link (added)
- ✅ Hybrid refund approach (added)
- ✅ Webhook error handling (already done)
