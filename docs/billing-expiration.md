# Billing Expiration System

## Overview

Enterprise-grade billing status expiration system for managing trial and comped accounts with automatic downgrades and audit trails.

## State Machine

### Billing Status Values

- **`null` / `'paid'`**: Normal billing via Stripe subscription - generates revenue
- **`'trial'`**: Trial period - no revenue during trial, expenses still apply
- **`'comped'`**: Free/complimentary account - no revenue ever, expenses still apply

### State Transitions

```
[No Account] → Paid (via Stripe)
[No Account] → Trial (manual assignment) → Paid (upgrade) OR Free (expiration)
[No Account] → Comped (manual assignment) → Paid (upgrade) OR Free (expiration)
Trial → Expired → Free (auto-downgrade)
Comped → Expired → Free (auto-downgrade)
Trial/Comped → Upgraded to Stripe → Paid (auto-cleared)
```

## Database Fields

- **`billing_status`**: Current billing status (paid/trial/comped)
- **`billing_status_expires_at`**: Optional expiration date for trial/comped accounts
- **`equivalent_plan_value`**: Sales insight tracking for comped accounts (NOT revenue)
- **`manual_plan_override`**: Plan name when manually assigned

## Automation

### Scheduled Commands

1. **`billing:check-expiring`** (Daily at 9:00 AM)
   - Checks for accounts expiring in next 7 days
   - TODO: Sends warning notifications
   - Logs for audit trail

2. **`billing:process-expired`** (Daily at 2:00 AM)
   - Finds accounts with `billing_status_expires_at` in the past
   - Processes expiration based on billing_status
   - Downgrades to free plan if not upgraded
   - Logs all actions

## Protections

1. **Stripe Subscription Check**: Never expires accounts with active Stripe subscriptions
2. **Audit Logging**: All state changes logged with metadata
3. **Dry-Run Mode**: Test expiration logic without making changes
4. **Grace Period Support**: TODO - Add grace period before downgrade

## Manual Plan Assignment

### Backend API

When assigning a plan manually via `/app/admin/companies/{tenant}/plan`:

**With Expiration** (Recommended):
```json
{
  "plan": "pro",
  "billing_status": "comped",
  "expiration_months": 6,
  "equivalent_plan_value": 99.00
}
```

**Without Expiration** (Legacy):
```json
{
  "plan": "pro",
  "management_source": "manual"
}
```

### Behavior

- **With Expiration**: Uses `BillingExpirationService::setBillingStatusWithExpiration()`
  - Sets plan, billing_status, expiration date
  - Sets equivalent_plan_value (for comped accounts)
  - Logs activity with full audit trail

- **Without Expiration**: Legacy behavior
  - Sets plan and billing_status='comped' (if no Stripe)
  - No expiration date (requires manual intervention)

## Expiration Process

### Trial Expiration

1. Check if account has active Stripe subscription (protection)
2. If yes: Clear billing_status, keep plan
3. If no: Downgrade to free plan
4. Clear expiration date
5. Log activity

### Comped Expiration

1. Check if account has active Stripe subscription (protection)
2. If yes: Clear billing_status, keep plan
3. If no: Downgrade to free plan
4. Clear expiration date and equivalent_plan_value
5. Log activity

## TODO / Future Enhancements

- [ ] Email notifications before expiration (7, 3, 1 days before)
- [ ] Admin dashboard alerts for expiring accounts
- [ ] Grace period logic (e.g., 7 days after expiration before downgrade)
- [ ] Bulk expiration processing
- [ ] Manual extension capability (via admin UI)
- [ ] UI to set expiration dates when assigning plans
- [ ] UI to extend expiration dates
- [ ] Option to keep comped accounts indefinitely (no expiration)
- [ ] Integration with Stripe to automatically clear trial when subscription starts

## Accounting Rules

### Revenue Recognition

- **Paid accounts**: Count actual Stripe invoice amounts (revenue)
- **Trial accounts**: $0 revenue during trial
- **Comped accounts**: $0 revenue always
- **equivalent_plan_value**: Sales insight only - NEVER count as revenue

### Expenses

- Deduct ALL expenses from ALL accounts (paid, trial, comped)
- Expenses include: AWS/S3, Compute, Email, Monitoring, CI/CD, SaaS tools, Contractors

### GAAP Compliance

- Do NOT invent revenue
- Do NOT invent discounts
- Only count actual Stripe income
- Comped accounts = $0 revenue, normal expenses
