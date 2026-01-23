# AI Usage Limits and Suggestions — Locked Phase

**Status:** ✅ LOCKED  
**Last Updated:** January 2025

---

## Overview

This document defines the AI usage tracking and suggestion system, including monthly caps, enforcement behavior, and reset mechanisms. **This phase is LOCKED** — do not modify usage tracking logic, enforcement behavior, or cap configuration without explicit instruction.

---

## Critical Guardrails

### ⚠️ DO NOT MODIFY

- **Usage tracking logic** (`AiUsageService`)
- **Enforcement behavior** (hard stops, cap checks)
- **Cap configuration** (`config/plans.php` AI limits)
- **Reset behavior** (monthly calendar-based reset)
- **Transaction safety** (race condition prevention)

### ✅ ALLOWED CHANGES

- UI improvements for displaying usage status
- Additional reporting/analytics (read-only)
- Configuration value updates (numbers only, no logic changes)

---

## AI Features: Tagging vs Suggestions

The system tracks two distinct AI features with separate usage limits:

### AI Tagging

**Purpose:** Automatic tagging of assets with AI-generated tags based on content analysis.

**When it runs:**
- During asset upload/processing
- As part of automated metadata extraction
- Background processing jobs

**Usage tracking:**
- Each AI tagging operation counts as 1 call
- Tracked in `ai_usage` table with `feature = 'tagging'`
- Subject to `max_ai_tagging_per_month` plan limit

**Current plan limits:**
- **Free:** 5 calls/month
- **Starter:** 100 calls/month
- **Pro:** 1,000 calls/month
- **Enterprise:** Unlimited (0 = unlimited)

### AI Suggestions

**Purpose:** AI-generated metadata suggestions displayed to users for manual review and acceptance.

**When it runs:**
- During asset processing (Phase H metadata extraction)
- Only when metadata fields are empty
- Only for fields marked `ai_eligible = true`
- Only for `select` or `multiselect` field types with defined `allowed_values`

**Usage tracking:**
- Each suggestion generation counts as 1 call
- Tracked in `ai_usage` table with `feature = 'suggestions'`
- Subject to `max_ai_suggestions_per_month` plan limit

**Current plan limits:**
- **Free:** 10 suggestions/month
- **Starter:** 500 suggestions/month
- **Pro:** 5,000 suggestions/month
- **Enterprise:** Unlimited (0 = unlimited)

**Key differences from tagging:**
- Suggestions are **ephemeral** (stored in `asset.metadata['_ai_suggestions']`)
- Suggestions require **explicit user acceptance** to become real metadata
- Suggestions are **never auto-applied**
- Suggestions respect **confidence thresholds** (>= 0.90)
- Suggestions respect **dismissal tracking** (dismissed values never reappear)

---

## Monthly Cap Behavior

### Cap Values

Caps are defined in `config/plans.php` under each plan's `limits` array:

```php
'max_ai_tagging_per_month' => 100,      // Positive number = cap
'max_ai_suggestions_per_month' => 500,  // Positive number = cap
```

### Special Values

- **`0`** = Unlimited (Enterprise plans only)
- **`-1`** = Disabled (not currently used)
- **Positive number** = Hard cap (enforced strictly)

### Cap Interpretation

The `AiUsageService::getMonthlyCap()` method interprets cap values:

```php
// 0 = unlimited, -1 = disabled, positive number = cap
if ($cap === 0) {
    return true; // Unlimited
}
if ($cap === -1) {
    return false; // Disabled
}
// Otherwise: enforce cap
```

**⚠️ CRITICAL:** Do not change this interpretation logic. It is fundamental to the system's behavior.

---

## Enforcement Timing

### Hard Stop Enforcement

**When:** Before any AI operation executes

**Where:** `AiUsageService::trackUsage()` and `AiUsageService::checkUsage()`

**How:**
1. Check current month's usage (sum of `call_count` for feature in current month)
2. Get monthly cap from plan configuration
3. If `(currentUsage + requestedCalls) > cap` → **Throw `PlanLimitExceededException`**
4. If cap would be exceeded, **operation is blocked** (hard stop)

### Transaction Safety

**Race condition prevention:**
- Uses `DB::transaction()` with `lockForUpdate()` on existing records
- Cap check occurs **inside transaction** before incrementing
- Prevents concurrent requests from exceeding cap

**Example flow:**
```php
DB::transaction(function () use ($tenant, $feature, $callCount) {
    // 1. Get current usage (within transaction)
    $currentUsage = /* sum from ai_usage table */;
    
    // 2. Check cap BEFORE incrementing (hard stop)
    if ($cap > 0 && ($currentUsage + $callCount) > $cap) {
        throw new PlanLimitExceededException(...);
    }
    
    // 3. Only increment if cap check passes
    // Use lockForUpdate() to prevent race conditions
    $existing = DB::table('ai_usage')
        ->where(...)
        ->lockForUpdate()
        ->first();
    
    // 4. Increment or insert
});
```

**⚠️ CRITICAL:** Do not remove transaction safety or move cap check outside transaction. This prevents cost overruns.

### Enforcement Points

**AI Tagging:**
- Enforced in metadata extraction jobs
- Enforced before calling AI tagging service
- If cap exceeded: Tagging is skipped (no error, silent skip)

**AI Suggestions:**
- Enforced in `AiMetadataSuggestionService::generateSuggestions()`
- Enforced before generating any suggestions
- If cap exceeded: Returns empty suggestions array (no error, silent skip)
- UI shows "AI suggestions paused until next month" notice

---

## Reset Behavior

### Monthly Calendar Reset

**Reset timing:** First day of each calendar month (00:00:00 UTC)

**How it works:**
- Usage is tracked by `usage_date` (date column)
- Monthly usage queries use `whereBetween(monthStart, monthEnd)`
- No explicit reset job needed — old data is simply not counted

**Month boundaries:**
```php
$monthStart = now()->startOfMonth()->toDateString(); // e.g., '2025-01-01'
$monthEnd = now()->endOfMonth()->toDateString();     // e.g., '2025-01-31'
```

**Example:**
- January usage: All records with `usage_date` between '2025-01-01' and '2025-01-31'
- February usage: All records with `usage_date` between '2025-02-01' and '2025-02-28'
- Old records remain in database but are not counted in current month

**⚠️ CRITICAL:** Do not add explicit reset jobs or delete old records. The calendar-based query approach is intentional and prevents data loss.

### Reset Behavior for Users

**When cap resets:**
- Automatically on the 1st of each month
- No user action required
- No notification sent (users see usage status in admin UI)

**UI indication:**
- Admin UI shows current month usage
- Progress bars reset to 0% on month boundary
- "Paused" notices disappear when new month begins

---

## Usage Tracking Architecture

### Database Schema

**Table:** `ai_usage`

```sql
- id (primary key)
- tenant_id (foreign key to tenants)
- feature ('tagging' or 'suggestions')
- usage_date (date) -- Used for monthly aggregation
- call_count (integer) -- Number of calls on this date
- created_at, updated_at
```

**Indexes:**
- `(tenant_id, feature, usage_date)` — Unique constraint
- `(tenant_id, usage_date)` — For monthly queries
- `(usage_date)` — For cleanup queries (if needed)

**⚠️ CRITICAL:** Do not modify this schema without understanding the aggregation logic.

### Service Layer

**Primary service:** `App\Services\AiUsageService`

**Key methods:**
- `trackUsage(Tenant $tenant, string $feature, int $callCount)` — Increment usage (with hard stop)
- `getMonthlyUsage(Tenant $tenant, string $feature)` — Get current month's total
- `getMonthlyCap(Tenant $tenant, string $feature)` — Get cap from plan config
- `canUseFeature(Tenant $tenant, string $feature)` — Check if feature can be used
- `checkUsage(Tenant $tenant, string $feature, int $requestedCalls)` — Pre-check before operation
- `getUsageStatus(Tenant $tenant)` — Get status for all features (for admin UI)
- `getUsageBreakdown(Tenant $tenant, string $feature)` — Get daily breakdown (for admin UI)

**⚠️ CRITICAL:** Do not modify these methods without understanding transaction safety requirements.

---

## Suggestion-Specific Behavior

### Suggestion Generation Rules

**Only generated when:**
1. Field is empty (no existing metadata value)
2. Field has `ai_eligible = true`
3. Field type is `select` or `multiselect`
4. Field has defined `allowed_values` (metadata_options)
5. AI confidence >= 0.90 (strict threshold)
6. Suggestion has not been dismissed for this asset+field+value combination
7. Monthly cap has not been exceeded

**Storage:**
- Suggestions stored in `asset.metadata['_ai_suggestions']` (ephemeral JSON)
- Dismissals stored in `asset.metadata['_ai_suggestions_dismissed']` (persistent JSON)
- Never merged into real metadata until user accepts

**⚠️ CRITICAL:** Do not auto-apply suggestions. They must remain ephemeral until explicit user acceptance.

### Cap Exceeded Behavior

**When cap is exceeded:**
- `AiMetadataSuggestionService::generateSuggestions()` returns empty array
- No suggestions are generated
- No error is thrown (silent skip)
- UI component (`AiMetadataSuggestionsInline`) detects cap exceeded and shows notice

**UI notice:**
- "AI suggestions paused until next month"
- Only visible to users with `metadata.suggestions.view` permission
- Replaces suggestion UI when cap exceeded

---

## Configuration

### Plan Configuration

**File:** `config/plans.php`

**Structure:**
```php
'free' => [
    'limits' => [
        'max_ai_tagging_per_month' => 5,
        'max_ai_suggestions_per_month' => 10,
    ],
],
```

**⚠️ CRITICAL:** 
- Do not change cap interpretation logic (0 = unlimited, -1 = disabled)
- Do not remove transaction safety from usage tracking
- Do not modify `AiUsageService` enforcement behavior

### AI Metadata Configuration

**File:** `config/ai_metadata.php`

**Suggestion thresholds:**
```php
'suggestions' => [
    'min_confidence' => 0.90, // Strict threshold
    'enabled' => true,
],
```

**⚠️ CRITICAL:** Do not lower `min_confidence` below 0.90. This prevents low-quality suggestions.

---

## Admin UI

### Usage Status Display

**Location:** Admin / Settings / AI Usage

**Visible to:** Users with `ai.usage.view` permission

**Displays:**
- Current month usage for each feature
- Monthly cap
- Percentage used
- Remaining calls
- Daily breakdown (optional)

**Endpoint:** `GET /api/companies/ai-usage`

**⚠️ CRITICAL:** This is read-only. Do not add editing controls or cap modification UI.

---

## Error Handling

### PlanLimitExceededException

**When thrown:**
- Cap would be exceeded by requested operation
- Feature is disabled (`cap === -1`)

**Response:**
- HTTP 403 with JSON error
- Message includes current usage, cap, and reset timing

**Handling:**
- AI operations catch exception and skip gracefully
- UI shows appropriate notice (for suggestions)
- No user-facing errors for tagging (silent skip)

---

## Testing Considerations

### Test Scenarios

**Must test:**
1. Cap enforcement (hard stop)
2. Transaction safety (concurrent requests)
3. Monthly reset behavior (calendar boundaries)
4. Unlimited plans (cap = 0)
5. Disabled features (cap = -1)
6. Suggestion generation rules (all conditions)
7. Dismissal tracking (no repeat suggestions)

**⚠️ CRITICAL:** Do not remove tests for transaction safety or cap enforcement.

---

## Migration and Backfill

### Historical Data

**No backfill needed:**
- Usage tracking started when feature was implemented
- Old assets do not need usage records
- Calendar-based queries handle month boundaries automatically

**⚠️ CRITICAL:** Do not add migration jobs to backfill usage data. It's not necessary.

---

## Future Considerations

### Potential Extensions (Future Phases)

**Allowed (non-breaking):**
- Additional reporting/analytics
- Usage alerts/notifications
- Overage billing (separate phase)
- Feature-level toggles (separate phase)

**Not allowed (breaking changes):**
- Changing cap interpretation (0 = unlimited)
- Removing transaction safety
- Changing monthly reset behavior
- Auto-applying suggestions
- Removing hard stop enforcement

---

## Summary

### Key Principles

1. **Hard stops prevent cost overruns** — Cap checks occur before operations, not after
2. **Transaction safety prevents race conditions** — Concurrent requests cannot exceed cap
3. **Calendar-based reset is automatic** — No jobs needed, queries handle month boundaries
4. **Suggestions are ephemeral** — Never auto-applied, require explicit user acceptance
5. **Separate tracking for each feature** — Tagging and suggestions have independent caps

### Lock Status

**✅ LOCKED — Do Not Modify:**
- `AiUsageService` enforcement logic
- Transaction safety mechanisms
- Cap interpretation (0 = unlimited, -1 = disabled)
- Monthly reset behavior (calendar-based)
- Suggestion generation rules
- Hard stop behavior

**✅ Allowed:**
- UI improvements (read-only displays)
- Configuration value updates (numbers only)
- Additional reporting (read-only)

---

**End of AI Usage Limits and Suggestions Documentation**
