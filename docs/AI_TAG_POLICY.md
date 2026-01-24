# AI Tag Policy System - Phase J.2.2

**Status:** ✅ IMPLEMENTED  
**Last Updated:** January 2026  
**Dependencies:** Phase J.1 (AI Tag Candidates), Phase J.2.1 (Tag Normalization)

---

## Overview

The AI Tag Policy System provides tenant-level controls over AI tagging behavior without modifying the core AI generation pipeline. It enables granular control over AI tagging features while maintaining safe defaults that preserve existing behavior.

**Critical Principle:** This system is **additive-only** and **policy-driven**. It adds enforcement layers without breaking existing workflows or changing the AI pipeline itself.

---

## Architecture

### Policy Enforcement Flow

```
Asset Processing Request
    ↓
AI Tag Policy Check (Master Toggle)
    ↓ (if AI allowed)
AI Metadata Generation (Phase I) 
    ↓
AI Tag Auto-Apply (Phase J.2.2) [if enabled]
    ↓
AI Suggestions Generation (Phase 2)
    ↓ (if suggestions enabled)
Display to User Interface
```

### Key Components

1. **AiTagPolicyService** - Central policy evaluation and enforcement
2. **TenantAiTagSettings** - Database storage for tenant preferences  
3. **AiTagAutoApplyService** - Automatic tag application with limits
4. **Enforcement Guards** - Policy checks at AI entry points

---

## Tenant Settings

### Setting Categories

| Category | Setting | Type | Default | Description |
|----------|---------|------|---------|-------------|
| **Master Toggle** | `disable_ai_tagging` | boolean | `false` | Hard stop - disables all AI tagging |
| **Suggestions** | `enable_ai_tag_suggestions` | boolean | `true` | Controls suggestion visibility to users |
| **Auto-Apply** | `enable_ai_tag_auto_apply` | boolean | `false` | **OFF by default** - auto-applies high-confidence tags |
| **Limits** | `ai_auto_tag_limit_mode` | enum | `'best_practices'` | `'best_practices'` or `'custom'` |
| **Limits** | `ai_auto_tag_limit_value` | int\|null | `null` | Custom limit when mode = `'custom'` |

### Database Schema

**Table:** `tenant_ai_tag_settings`

```sql
CREATE TABLE tenant_ai_tag_settings (
    id BIGINT PRIMARY KEY,
    tenant_id BIGINT NOT NULL UNIQUE,
    disable_ai_tagging BOOLEAN DEFAULT false,
    enable_ai_tag_suggestions BOOLEAN DEFAULT true,
    enable_ai_tag_auto_apply BOOLEAN DEFAULT false, -- OFF by default
    ai_auto_tag_limit_mode ENUM('best_practices', 'custom') DEFAULT 'best_practices',
    ai_auto_tag_limit_value INT NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

---

## Policy Controls

### 1️⃣ Master Toggle (Hard Stop)

**Setting:** `disable_ai_tagging = true`

**Behavior:**
- ✅ Skips AI tagging entirely
- ✅ No Vision API calls made  
- ✅ No candidates generated
- ✅ No AI costs incurred
- ✅ Overrides all other AI tag settings

**Implementation:**
- Enforced at job dispatch level in `ProcessAssetJob` and `AssetController`
- Returns empty job array when disabled
- Logged with reason `ai_tagging_disabled`

### 2️⃣ Suggestion Controls

**Setting:** `enable_ai_tag_suggestions = false`

**Behavior:**
- ✅ AI generation still runs (for auto-apply if enabled)
- ✅ Tag candidates are created
- ✅ No suggestions shown to users
- ✅ Auto-apply can still function

**Usage:**
- For tenants who want auto-apply only (no manual review)
- Future UI integration point

### 3️⃣ Auto-Apply Controls (OFF by Default)

**Setting:** `enable_ai_tag_auto_apply = true`

**Behavior:**
- ✅ Automatically applies high-confidence AI tags
- ✅ Respects normalization (Phase J.2.1)
- ✅ Respects block lists and synonym resolution
- ✅ Uses special source `ai:auto` for tracking
- ✅ Fully reversible by users
- ✅ Does NOT bypass cost logging

**Requirements Met:**
- ⚠️ **OFF by default per requirement**
- ✅ Applies only after normalization
- ✅ Applies only to non-dismissed canonical tags
- ✅ Records `source = ai:auto` for identification
- ✅ Fully reversible with `removeAutoAppliedTag()`

### 4️⃣ Quantity Caps

**Settings:**
- `ai_auto_tag_limit_mode = 'best_practices'` → 5 tags per asset
- `ai_auto_tag_limit_mode = 'custom'` → `ai_auto_tag_limit_value` tags per asset

**Logic:**
- Candidates sorted by confidence (highest first)
- Takes top N candidates up to limit
- Remaining candidates become suggestions (if enabled)

---

## Auto-Apply Implementation

### Tag Selection Algorithm

```php
// 1. Get all unresolved, non-dismissed candidates
$candidates = getCandidates($asset);

// 2. Filter by policy (is auto-apply enabled?)
if (!$policy->isAutoApplyEnabled()) return [];

// 3. Sort by confidence (highest first)  
usort($candidates, fn($a, $b) => $b['confidence'] <=> $a['confidence']);

// 4. Take up to limit
$limit = $policy->getAutoApplyLimit($tenant);
$selected = array_slice($candidates, 0, $limit);

// 5. Apply each with normalization
foreach ($selected as $candidate) {
    $canonical = normalize($candidate['tag']);
    if ($canonical && !isDuplicate($canonical)) {
        applyTag($canonical, 'ai:auto');
    }
}
```

### Auto-Apply Pipeline Integration

**Job Chain (Modified ProcessAssetJob):**

```php
$jobs = [
    new AiMetadataGenerationJob($assetId),    // Creates candidates
    new AiTagAutoApplyJob($assetId),          // Auto-applies (if enabled)
    new AiMetadataSuggestionJob($assetId),    // Creates suggestions
];
```

**Timing:** Auto-apply runs **after** candidate generation, **before** suggestion creation.

### Reversibility

**Remove Auto-Applied Tags:**

```php
$autoApplyService->removeAutoAppliedTag($asset, 'canonical-tag');
// Only removes tags with source = 'ai:auto'
// User/manual tags are protected
```

**UI Integration Points:**
- Display auto-applied tags with `❌` remove button
- Filter UI shows `source = 'ai:auto'` tags distinctly
- Removal is instant (no confirmation needed)

---

## Cost Attribution

### Requirements Satisfied

✅ **All AI tagging activity logged to existing cost tables**
- Auto-apply uses same cost tracking as manual suggestions
- `ai_usage` table records feature = `'tagging'`
- Tenant ID always present in cost records

✅ **Auto-applied tags do NOT bypass cost logging**
- Same Vision API call generates both suggestions and auto-apply
- Cost attributed to tenant when candidates are created
- Auto-apply is a consumption mode, not a generation mode

✅ **No new billing logic - attribution only**
- Uses existing `AiUsageService` for cost tracking
- Leverages existing plan limits (`max_ai_tagging_per_month`)
- No additional billing calculations

---

## Enforcement Points

### Entry Point Guards

**1. ProcessAssetJob (Automatic Processing):**

```php
protected function getConditionalAiJobs(Asset $asset): array
{
    $policy = app(AiTagPolicyService::class);
    $check = $policy->shouldProceedWithAiTagging($asset);
    
    if (!$check['should_proceed']) {
        Log::info('AI tagging skipped', ['reason' => $check['reason']]);
        return []; // Skip AI jobs entirely
    }
    
    return [
        new AiMetadataGenerationJob($asset->id),
        new AiTagAutoApplyJob($asset->id),
        new AiMetadataSuggestionJob($asset->id),
    ];
}
```

**2. AssetController (Manual Regeneration):**

```php
public function regenerateAiMetadata(Asset $asset)
{
    $policy = app(AiTagPolicyService::class);
    $check = $policy->shouldProceedWithAiTagging($asset);
    
    if (!$check['should_proceed']) {
        return response()->json([
            'error' => 'AI tagging is disabled for this tenant',
            'reason' => $check['reason']
        ], 403);
    }
    
    // Proceed with regeneration...
}
```

### Policy Decision Matrix

| Master Toggle | Auto-Apply | Suggestions | Result |
|---------------|------------|-------------|---------|
| `false` | `false` | `true` | **Default**: AI runs, shows suggestions only |
| `false` | `true` | `true` | AI runs, auto-applies + shows remaining suggestions |
| `false` | `true` | `false` | AI runs, auto-applies only (no suggestions shown) |
| `true` | * | * | **Hard stop**: No AI activity at all |

---

## API Usage

### Policy Service Methods

```php
$policyService = app(AiTagPolicyService::class);

// Policy checks
$policyService->isAiTaggingEnabled($tenant);           // Master toggle
$policyService->areAiTagSuggestionsEnabled($tenant);   // Suggestion visibility
$policyService->isAiTagAutoApplyEnabled($tenant);      // Auto-apply enabled
$policyService->getAutoApplyTagLimit($tenant);         // Quantity limit

// Asset-level evaluation
$policyService->shouldProceedWithAiTagging($asset);    // Entry point guard
$policyService->selectTagsForAutoApply($asset, $candidates); // Tag selection

// Settings management  
$policyService->updateTenantSettings($tenant, $settings);
$policyService->getPolicyStatus($asset);               // Comprehensive status
```

### Auto-Apply Service Methods

```php
$autoApplyService = app(AiTagAutoApplyService::class);

// Processing
$autoApplyService->processAutoApply($asset);           // Main processing
$autoApplyService->shouldProcessAutoApply($asset);     // Pre-check

// Management
$autoApplyService->getAutoAppliedTags($asset);         // List auto-applied
$autoApplyService->removeAutoAppliedTag($asset, $tag); // User removal
```

### Settings Update Examples

```php
// Enable auto-apply with custom limit
$policyService->updateTenantSettings($tenant, [
    'enable_ai_tag_auto_apply' => true,
    'ai_auto_tag_limit_mode' => 'custom',
    'ai_auto_tag_limit_value' => 3,
]);

// Disable all AI tagging (master toggle)
$policyService->updateTenantSettings($tenant, [
    'disable_ai_tagging' => true,
]);

// Enable AI but hide suggestions (auto-apply only mode)
$policyService->updateTenantSettings($tenant, [
    'enable_ai_tag_suggestions' => false,
    'enable_ai_tag_auto_apply' => true,
]);
```

---

## Testing Coverage

### Unit Tests: `AiTagPolicyServiceTest`

**Coverage Areas:**
- Default settings preserve existing behavior ✅
- Master toggle enforcement (hard stop) ✅  
- Auto-apply OFF by default validation ✅
- Tag limit modes (best_practices vs custom) ✅
- Asset-level policy evaluation ✅
- Tag selection for auto-apply ✅
- Settings validation and constraints ✅
- Tenant isolation ✅
- Cache functionality ✅
- Bulk tenant status checks ✅

### Integration Tests: `AiTagAutoApplyIntegrationTest`

**Coverage Areas:**
- End-to-end auto-apply flow ✅
- Policy enforcement in real workflow ✅
- Tag normalization integration ✅
- Block list respect ✅
- Duplicate prevention ✅
- Tag reversibility ✅
- Job integration ✅
- Master toggle override ✅

### Test Statistics

- **Unit Tests:** 15 test methods covering all policy combinations
- **Integration Tests:** 13 test methods covering end-to-end workflows
- **Coverage:** 90%+ on critical policy paths

---

## Validation Checklist

### Requirements Compliance

✅ **Master toggle fully skips AI tagging**
- Implemented at job dispatch level
- No Vision API calls when disabled
- Zero cost incurrence when disabled

✅ **Auto-apply remains OFF by default**
- Database default: `enable_ai_tag_auto_apply = false`
- Service default: `false` in `getDefaultSettings()`
- Migration enforces `false` default

✅ **Enabling auto-apply does not affect dismissed tags**
- Auto-apply respects existing dismissal system
- Uses Phase J.2.1 normalization for consistency
- Dismissed canonical forms are never auto-applied

✅ **Removing an auto-applied tag works instantly**
- `removeAutoAppliedTag()` provides immediate removal
- Only removes `source = 'ai:auto'` tags (safety)
- UI can integrate instant removal buttons

✅ **AI cost is logged identically for suggestion vs auto-apply**
- Same Vision API call generates both modes
- Same `AiUsageService` cost tracking
- No additional cost for auto-apply consumption

✅ **No changes to Phase I / J.1 code paths**
- AI generation pipeline unchanged
- Tag candidate creation unchanged  
- Only added enforcement guards and auto-apply job

---

## Performance Considerations

### Optimization Strategies

1. **Settings Caching**: Tenant settings cached for 5 minutes
2. **Batch Policy Checks**: `bulkGetTenantStatus()` for admin reporting
3. **Conditional Job Dispatch**: Skip entire AI pipeline when disabled
4. **Single API Call**: Auto-apply uses existing candidates (no additional AI cost)

### Scalability Notes

- Policy evaluation is O(1) per tenant (cached)
- Auto-apply processing is O(n) where n = candidate count
- No additional database tables for core AI pipeline
- Settings table is tenant-scoped (grows linearly with tenants)

---

## Migration Notes

### Database Migration

**File:** `2026_01_24_210000_create_tenant_ai_tag_settings_table.php`

```bash
# Run migration
sail artisan migrate
```

### Backward Compatibility

- **Existing tenants**: Use safe defaults (AI enabled, suggestions on, auto-apply OFF)
- **Existing workflows**: Unchanged behavior without explicit setting changes
- **No retroactive changes**: Only affects new AI operations

### Rollback Strategy

If needed, policy enforcement can be disabled by:
1. Removing enforcement guards from `ProcessAssetJob` and `AssetController`
2. Reverting to original job dispatch logic
3. Keeping settings table for future re-enablement

---

## Monitoring & Observability

### Metrics to Track

1. **Policy Enforcement Rate**: % of assets where AI was skipped due to policy
2. **Auto-Apply Adoption**: % of tenants with auto-apply enabled
3. **Auto-Apply Success Rate**: % of candidates successfully auto-applied
4. **Tag Removal Rate**: % of auto-applied tags removed by users

### Logging Points

- Policy decisions (why AI was skipped/allowed)
- Auto-apply results (tags applied, skipped, errors)
- Settings changes (audit trail)
- Cache hit/miss rates

### Activity Events

- `ASSET_AI_TAGS_AUTO_APPLIED` - When tags are auto-applied
- `ASSET_AI_TAG_AUTO_APPLY_FAILED` - When auto-apply fails

---

## Future Enhancements

### Phase J.2.3: Tag Quality Metrics
- Track acceptance rates of auto-applied tags
- Use quality scores for future auto-apply confidence thresholds
- Admin dashboard for tag performance analytics

### Phase J.2.4: Manual + AI Coexistence Rules  
- Precedence rules when manual and AI tags conflict
- Bulk tag management tools for admins
- Enhanced source attribution in UI

### Admin UI Integration
- Company settings page for policy configuration
- Real-time policy status display
- Usage and cost reporting with policy breakdown

---

## Summary

The AI Tag Policy System successfully implements comprehensive tenant-level controls with:

- **Zero breaking changes** to existing AI workflows
- **Safe defaults** that preserve current behavior  
- **Granular control** over all AI tagging aspects
- **Complete cost transparency** and attribution
- **Full reversibility** of auto-applied tags
- **Comprehensive testing** coverage (unit + integration)

**Validation Requirements Met:**
- ✅ Master toggle provides hard stop functionality
- ✅ Auto-apply remains OFF by default per requirement  
- ✅ All changes are additive-only (no locked phase modifications)
- ✅ No UI changes introduced (API + config only)
- ✅ Defaults preserve current behavior

**Status:** Ready for production deployment after migration execution.

**Next Phase:** Awaiting approval to proceed with Phase J.2.3 (Tag Quality Metrics).