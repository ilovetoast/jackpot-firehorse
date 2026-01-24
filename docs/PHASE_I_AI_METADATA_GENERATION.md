# Phase I: AI Metadata Generation System

**Status:** 📋 PLANNING  
**Last Updated:** January 2025  
**Dependencies:** Phase C (Metadata Governance), AI Usage Limits System

---

## Overview

This phase implements enterprise-grade AI metadata generation for assets, enabling automatic analysis of image content to generate metadata suggestions for fields marked as `ai_eligible = true`. The system integrates with OpenAI (and future providers) to analyze asset images and generate high-confidence metadata candidates that flow into the existing suggestion system.

**Critical Principle:** This system ONLY generates metadata for fields with `ai_eligible = true`. System fields (like `color_space`, `orientation`, `dimensions`) that can be determined system-wide are NOT processed by AI - they use deterministic algorithms.

---

## Architecture Overview

### High-Level Flow

```
Asset Upload
    ↓
Thumbnail Generation (Phase 3)
    ↓
AI Metadata Generation Job (NEW)
    ├─ Check plan limits (hard stop if exceeded)
    ├─ Fetch ai_eligible fields for asset's category
    ├─ Call OpenAI Vision API with image + field definitions
    ├─ Parse AI response into candidates
    ├─ Create candidates in asset_metadata_candidates (producer='ai')
    ├─ Track usage and cost
    └─ Log activity
    ↓
AiMetadataSuggestionJob (EXISTING)
    ├─ Reads candidates from asset_metadata_candidates
    ├─ Applies eligibility filters
    └─ Generates suggestions
```

### Key Components

1. **AiMetadataGenerationJob** - Main job that orchestrates AI analysis
2. **AiMetadataGenerationService** - Core service that calls OpenAI and creates candidates
3. **AiMetadataPromptBuilder** - Constructs prompts with field definitions and options
4. **AiMetadataResponseParser** - Parses AI responses into structured candidates
5. **Cost Tracking Extension** - Extends `AiUsageService` for cost attribution
6. **Admin Rerun UI** - Admin interface to manually trigger regeneration

---

## 1. AI Model Selection & Configuration

### Recommended Models (2025)

Based on current market analysis:

**Primary: OpenAI GPT-4 Vision (gpt-4o)**
- **Strengths:** Excellent image understanding, structured output support, reliable API
- **Cost:** ~$0.01 per 1K input tokens, ~$0.03 per 1K output tokens
- **Best for:** Production use, enterprise reliability
- **Context Window:** 128K tokens (sufficient for multiple fields)

**Alternative: Google Gemini 1.5 Pro**
- **Strengths:** Native multimodal, efficient reasoning, competitive pricing
- **Cost:** Similar to GPT-4 Vision
- **Best for:** Future provider diversification

**Alternative: Anthropic Claude 3.5 Sonnet (with vision)**
- **Strengths:** Enterprise security, constitutional AI, strong safety guardrails
- **Cost:** Competitive
- **Best for:** Security-sensitive environments

### Model Configuration

**File:** `config/ai.php` (extend existing)

```php
'models' => [
    // ... existing models ...
    
    'gpt-4o' => [
        'provider' => 'openai',
        'model_name' => 'gpt-4o',
        'capabilities' => ['text', 'reasoning', 'image', 'multimodal'],
        'recommended_use' => ['tagging', 'metadata_generation'],
        'default_cost_per_token' => [
            'input' => 0.00001,  // $0.01 per 1K tokens
            'output' => 0.00003, // $0.03 per 1K tokens
        ],
        'active' => true,
    ],
    
    'gpt-4o-mini' => [
        'provider' => 'openai',
        'model_name' => 'gpt-4o-mini',
        'capabilities' => ['text', 'reasoning', 'image', 'multimodal'],
        'recommended_use' => ['tagging', 'metadata_generation'],
        'default_cost_per_token' => [
            'input' => 0.00000015,  // $0.15 per 1M tokens (much cheaper)
            'output' => 0.0000006,  // $0.60 per 1M tokens
        ],
        'active' => true,
        'notes' => 'Cost-effective alternative for high-volume operations',
    ],
],
```

### Model Selection Strategy

**Default:** `gpt-4o-mini` for cost efficiency (recommended)  
**Opt-in:** `gpt-4o` for accuracy-critical use cases (configurable per tenant)  
**Future:** Provider abstraction allows switching without code changes

**Recommendation:** **Default to GPT-4o-mini. GPT-4o is opt-in.** This protects margins while still leaving upgrade paths for tenants who need higher accuracy.

---

## 2. Service Architecture

### AiMetadataGenerationService

**Purpose:** Core service that orchestrates AI metadata generation for a single asset.

**Responsibilities:**
- Fetch ai_eligible fields for asset's category
- Build structured prompt with field definitions and options
- Call OpenAI Vision API with image
- Parse structured response into candidates
- Create candidates in `asset_metadata_candidates` table
- Track usage and cost
- Handle errors gracefully (never block upload)

**Key Methods:**

```php
class AiMetadataGenerationService
{
    /**
     * Generate AI metadata candidates for an asset.
     * 
     * @param Asset $asset
     * @return array Results: ['candidates_created' => int, 'cost' => float, 'fields_processed' => array]
     * @throws PlanLimitExceededException If plan limit exceeded
     */
    public function generateMetadata(Asset $asset): array
    
    /**
     * Get ai_eligible fields for asset's category.
     * 
     * @param Asset $asset
     * @return array Array of field definitions with options
     */
    protected function getEligibleFields(Asset $asset): array
    
    /**
     * Build structured prompt for OpenAI.
     * 
     * @param Asset $asset
     * @param array $fields Field definitions
     * @return string Prompt text
     */
    protected function buildPrompt(Asset $asset, array $fields): string
    
    /**
     * Parse OpenAI response into candidates.
     * 
     * @param string $response JSON response from OpenAI
     * @param Asset $asset
     * @param array $fields Field definitions
     * @return array Candidates ready for database insertion
     */
    protected function parseResponse(string $response, Asset $asset, array $fields): array
}
```

### Prompt Structure

**Strategy:** Single API call with all fields to minimize cost and latency.

**Prompt Template:**

```
Analyze this image and provide metadata values for the following fields.
For each field, select ONLY from the provided allowed values.
Return a JSON object with field keys and selected values.

Image: [Base64 encoded thumbnail or signed URL]

Fields to analyze:
{
  "photo_type": {
    "label": "Photo Type",
    "type": "select",
    "allowed_values": ["action", "portrait", "landscape", "product", "lifestyle", "event"],
    "description": "The type or style of photograph"
  },
  "usage_rights": {
    "label": "Usage Rights",
    "type": "select",
    "allowed_values": ["editorial", "commercial", "restricted"],
    "description": "Legal usage rights for this image"
  }
}

Requirements:
- Only return fields where you have high confidence (>= 0.90)
- Include confidence score for each value
- If confidence is below 0.90, omit that field
- Values must exactly match one of the allowed_values
- Return JSON format: {"field_key": {"value": "...", "confidence": 0.95}}

Response:
```

**Optimization:** For many fields, consider batching or using structured output (OpenAI JSON mode).

### Response Format

**Expected JSON Response:**

```json
{
  "photo_type": {
    "value": "landscape",
    "confidence": 0.95,
    "reasoning": "Image shows a wide landscape scene with mountains"
  },
  "usage_rights": {
    "value": "editorial",
    "confidence": 0.92,
    "reasoning": "Appears to be a news/documentary style image"
  }
}
```

**Validation:**
- Values must be in allowed_values
- Confidence must be >= 0.90
- Field keys must match ai_eligible fields

---

## 3. Job Implementation

### AiMetadataGenerationJob

**Queue:** `default` (or dedicated `ai` queue for future scaling)

**Tries:** 3 (with exponential backoff)

**Dependencies:**
- Thumbnail generation must be complete (medium thumbnail available)
- Asset must have a category assigned

**Flow:**

```php
class AiMetadataGenerationJob implements ShouldQueue
{
    public bool $isManualRerun = false;
    
    public function __construct(int $assetId, bool $isManualRerun = false)
    {
        $this->assetId = $assetId;
        $this->isManualRerun = $isManualRerun;
    }
    
    public function handle(
        AiMetadataGenerationService $service,
        AiUsageService $usageService
    ): void {
        $asset = Asset::findOrFail($this->assetId);
        
        // 1. Check if already generated (unless manual rerun)
        // Auto-generation runs once per asset
        // Manual regenerate (via admin) overrides this check
        $metadata = $asset->metadata ?? [];
        $alreadyGenerated = isset($metadata['_ai_metadata_generated_at']);
        
        if ($alreadyGenerated && !$this->isManualRerun) {
            Log::info('[AiMetadataGenerationJob] Skipping - already generated', [
                'asset_id' => $asset->id,
                'generated_at' => $metadata['_ai_metadata_generated_at'],
            ]);
            return;
        }
        
        // 2. Check plan limits (hard stop)
        $tenant = Tenant::find($asset->tenant_id);
        $usageService->checkUsage($tenant, 'tagging', 1);
        
        // 3. Verify prerequisites
        if (!$asset->medium_thumbnail_url) {
            Log::info('[AiMetadataGenerationJob] Skipping - no thumbnail');
            return;
        }
        
        if (!$asset->metadata['category_id']) {
            Log::info('[AiMetadataGenerationJob] Skipping - no category');
            return;
        }
        
        // 4. Generate metadata
        try {
            $results = $service->generateMetadata($asset);
            
            // 5. Mark as generated (prevents silent re-runs)
            // This timestamp is updated even on manual rerun
            $asset->metadata = array_merge($asset->metadata ?? [], [
                '_ai_metadata_generated_at' => now()->toIso8601String(),
            ]);
            $asset->save();
            
            // 6. Track usage and cost
            $usageService->trackUsage($tenant, 'tagging', 1);
            // Cost tracking handled by service
            
            // 7. Log activity
            ActivityRecorder::logAsset($asset, EventType::ASSET_AI_METADATA_GENERATED, [
                'candidates_created' => $results['candidates_created'],
                'cost' => $results['cost'],
                'fields_processed' => $results['fields_processed'],
                'is_manual_rerun' => $this->isManualRerun,
            ]);
            
        } catch (PlanLimitExceededException $e) {
            // Hard stop - don't retry, log and skip
            Log::warning('[AiMetadataGenerationJob] Plan limit exceeded', [
                'asset_id' => $asset->id,
                'tenant_id' => $tenant->id,
            ]);
            // Mark as skipped in metadata
            $this->markAsSkipped($asset, 'plan_limit_exceeded');
            return;
        } catch (\Throwable $e) {
            // AI failures must not affect upload success
            Log::error('[AiMetadataGenerationJob] Failed', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
            $this->markAsFailed($asset, $e->getMessage());
            // Don't throw - allow job to complete
        }
    }
}
```

**Integration Point:** Dispatched after thumbnail generation in `ProcessAssetJob` chain.

**⚠️ Naming Clarification:**
- **`AiMetadataGenerationJob`** → Vision-based field inference (this job) - analyzes images to generate metadata candidates for `ai_eligible` fields
- **`AiMetadataSuggestionJob`** → Human-facing suggestion builder (existing) - reads candidates and creates user-facing suggestions
- **`AITaggingJob`** → Reserved for freeform/global tags only (if implemented separately)

These are distinct systems with different purposes. Keep naming explicit to avoid confusion.

---

## 4. Cost Tracking & Attribution

### Extending AiUsageService

**Current State:** Tracks usage counts per feature (`tagging`, `suggestions`)

**Extension Needed:** Track actual costs, not just call counts.

**⚠️ Cost Tracking Strategy:**

**Option 1 (Recommended for Phase I.1-2):** Extend existing `ai_usage` table with nullable cost columns
- Add `cost_usd DECIMAL(10, 6) NULL`
- Add `tokens_in INT NULL`
- Add `tokens_out INT NULL`
- Add `model VARCHAR(100) NULL`
- Backward compatible (existing rows have NULL costs)
- Simpler than new table

**Option 2 (Optional - Phase I.3+):** New `ai_usage_costs` table for detailed cost breakdown
- Separate table allows more detailed tracking
- Can be added later without migration complexity
- Useful for advanced analytics

**Recommendation:** Start with Option 1 (extend `ai_usage`). Add `ai_usage_costs` table only if detailed cost analytics are needed in Phase I.3+.

**New Table (Optional - Phase I.3+):** `ai_usage_costs` (or extend `ai_usage`)

```sql
CREATE TABLE ai_usage_costs (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    feature VARCHAR(50) NOT NULL, -- 'tagging', 'suggestions'
    usage_date DATE NOT NULL,
    call_count INT NOT NULL DEFAULT 1,
    cost_usd DECIMAL(10, 6) NOT NULL DEFAULT 0.000000, -- Track actual cost
    tokens_in INT DEFAULT NULL,
    tokens_out INT DEFAULT NULL,
    model VARCHAR(100) DEFAULT NULL, -- 'gpt-4o', 'gpt-4o-mini'
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    INDEX idx_tenant_feature_date (tenant_id, feature, usage_date),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);
```

**Service Extension:**

```php
// In AiUsageService
public function trackUsageWithCost(
    Tenant $tenant, 
    string $feature, 
    int $callCount, 
    float $costUsd,
    ?int $tokensIn = null,
    ?int $tokensOut = null,
    ?string $model = null
): void {
    // Check usage cap first (existing logic)
    $this->checkUsage($tenant, $feature, $callCount);
    
    // Track usage count (existing)
    $this->trackUsage($tenant, $feature, $callCount);
    
    // Track cost (new)
    DB::table('ai_usage_costs')->insertOrUpdate([
        'tenant_id' => $tenant->id,
        'feature' => $feature,
        'usage_date' => now()->toDateString(),
        'call_count' => $callCount,
        'cost_usd' => DB::raw("cost_usd + {$costUsd}"),
        'tokens_in' => $tokensIn,
        'tokens_out' => $tokensOut,
        'model' => $model,
        'updated_at' => now(),
    ], [
        'cost_usd' => DB::raw("cost_usd + {$costUsd}"),
        'tokens_in' => DB::raw("COALESCE(tokens_in, 0) + " . ($tokensIn ?? 0)),
        'tokens_out' => DB::raw("COALESCE(tokens_out, 0) + " . ($tokensOut ?? 0)),
        'updated_at' => now(),
    ]);
}
```

**Cost Calculation:**

```php
// In AiMetadataGenerationService
protected function calculateCost(array $usage): float
{
    $model = $usage['model'] ?? 'gpt-4o';
    $tokensIn = $usage['tokens_in'] ?? 0;
    $tokensOut = $usage['tokens_out'] ?? 0;
    
    $provider = app(AIProviderInterface::class); // Resolve from config
    return $provider->calculateCost($tokensIn, $tokensOut, $model);
}
```

---

## 5. Plan Limits & Gating

### Existing Infrastructure

**Already Implemented:**
- `AiUsageService` with hard stop enforcement
- Plan limits in `config/plans.php` (`max_ai_tagging_per_month`)
- Transaction-safe usage tracking
- Monthly calendar-based reset

### Integration Points

**Before AI Generation:**
```php
// In AiMetadataGenerationJob
$usageService->checkUsage($tenant, 'tagging', 1);
// Throws PlanLimitExceededException if exceeded - hard stop
```

**After Successful Generation:**
```php
// Track usage (count)
$usageService->trackUsage($tenant, 'tagging', 1);

// Track cost (new)
$usageService->trackUsageWithCost(
    $tenant, 
    'tagging', 
    1, 
    $costUsd,
    $tokensIn,
    $tokensOut,
    $model
);
```

### Plan Limit Behavior

**Hard Stop:** If limit exceeded, job skips gracefully (no error, no retry)
- Logs warning
- Marks asset metadata with skip reason
- Continues processing pipeline

**No Retry on Limit:** Jobs that fail due to plan limits should NOT retry
- Check limit before job execution
- If exceeded, skip immediately
- Don't queue retry

---

## 6. Admin Rerun Functionality

### UI Location

**Asset Details Modal/Drawer** - Add "Regenerate AI Metadata" button

**Visibility:** Only for users with `assets.ai_metadata.regenerate` permission (admin/owner)

**Location:** In asset detail drawer, under "Thumbnail Management" section or separate "AI Metadata" section

### Implementation

**Controller Method:**

```php
// In AssetController or new AiMetadataController
public function regenerateAiMetadata(Asset $asset): JsonResponse
{
    $user = Auth::user();
    $tenant = app('tenant');
    
    // Permission check
    if (!$user->hasPermissionForTenant($tenant, 'assets.ai_metadata.regenerate')) {
        abort(403, 'You do not have permission to regenerate AI metadata.');
    }
    
    // Check plan limits
    $usageService = app(AiUsageService::class);
    try {
        $usageService->checkUsage($tenant, 'tagging', 1);
    } catch (PlanLimitExceededException $e) {
        return response()->json([
            'error' => 'Plan limit exceeded',
            'message' => $e->getMessage(),
        ], 403);
    }
    
    // **CRITICAL:** Manual regenerate does NOT clear dismissals
    // The _ai_suggestions_dismissed array must persist across regenerations
    // This prevents users from seeing previously dismissed suggestions again
    
    // Dispatch job with manual rerun flag (will update _ai_metadata_generated_at timestamp)
    // ⚠️ CRITICAL: Manual rerun does NOT clear _ai_suggestions_dismissed
    AiMetadataGenerationJob::dispatch($asset->id, isManualRerun: true);
    
    // Log activity
    ActivityRecorder::logAsset($asset, EventType::ASSET_AI_METADATA_REGENERATED, [
        'triggered_by' => $user->id,
        'triggered_at' => now()->toIso8601String(),
    ]);
    
    return response()->json([
        'success' => true,
        'message' => 'AI metadata regeneration queued',
    ]);
}
```

**Frontend Component:**

```jsx
// In AssetDrawer or AssetDetailsModal
{canRegenerateAiMetadata && (
    <button
        onClick={handleRegenerateAiMetadata}
        disabled={regenerating}
        className="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white"
    >
        {regenerating ? (
            <>
                <ArrowPathIcon className="h-4 w-4 mr-2 animate-spin" />
                Regenerating...
            </>
        ) : (
            <>
                <SparklesIcon className="h-4 w-4 mr-2" />
                Regenerate AI Metadata
            </>
        )}
    </button>
)}
```

**Route:**

```php
// In routes/web.php
Route::post('/app/assets/{asset}/ai-metadata/regenerate', [AssetController::class, 'regenerateAiMetadata'])
    ->middleware(['auth', 'tenant'])
    ->name('assets.ai-metadata.regenerate');
```

---

## 7. Activity Logging

### Event Types

**New Event:** `ASSET_AI_METADATA_GENERATED`

**Properties:**
- `candidates_created` (int)
- `cost` (float)
- `fields_processed` (array of field keys)
- `model` (string)
- `tokens_in` (int)
- `tokens_out` (int)

**New Event:** `ASSET_AI_METADATA_REGENERATED`

**Properties:**
- `triggered_by` (user_id)
- `triggered_at` (ISO 8601 timestamp)
- `reason` (optional: 'manual', 'retry', etc.)

**⚠️ CRITICAL RULE:** Manual regeneration does **NOT** clear `_ai_suggestions_dismissed`. This array must persist across regenerations to prevent users from seeing previously dismissed suggestions again. This is a hard requirement - future engineers must not "optimize away" this behavior.

### Logging Points

1. **After successful generation** - Log with full context
2. **After manual regeneration** - Log admin action
3. **On plan limit exceeded** - Log skip reason
4. **On errors** - Log error details (don't block pipeline)

---

## 8. Vector Database Integration (Future)

### TODO: Vector Database Reference

**Future Enhancement:** Store image embeddings in vector database for:
- Similarity search ("find images like this")
- Batch processing optimization (similar images processed together)
- Learning from previous selections (user feedback loop)

**Design Notes:**
- Use existing vector DB (Pinecone, Weaviate, or PostgreSQL pgvector)
- Store embeddings per asset in `asset_embeddings` table
- Reference previous similar assets in AI prompts for context
- Allow admins to configure "learning from history" feature

**Implementation:** Deferred to future phase. Add TODO comments in code.

**Code Comment Template:**

```php
/**
 * Generate AI metadata candidates for an asset.
 * 
 * TODO (Future - Vector DB Integration):
 * - Store image embedding in vector database
 * - Query similar assets for context
 * - Include "similar assets had these values" in prompt
 * - Learn from user acceptances/rejections
 * 
 * @param Asset $asset
 * @return array
 */
```

---

## 9. Field Eligibility & Filtering

### Only ai_eligible Fields

**Critical Rule:** This system ONLY processes fields where `ai_eligible = true`

**Excluded Fields:**
- System-determined fields (`color_space`, `orientation`, `dimensions`, `resolution_class`)
- Fields without `ai_eligible = true`
- Fields not enabled for asset's category
- Fields that already have values (empty fields only)

### Field Selection Logic

```php
protected function getEligibleFields(Asset $asset): array
{
    // Get category
    $categoryId = $asset->metadata['category_id'] ?? null;
    if (!$categoryId) {
        return []; // No category = no fields
    }
    
    // Get ai_eligible fields
    $fields = DB::table('metadata_fields')
        ->where('ai_eligible', true)
        ->where('type', 'in', ['select', 'multiselect'])
        ->where(function ($query) use ($asset) {
            $query->where('scope', 'system')
                ->orWhere(function ($q) use ($asset) {
                    $q->where('tenant_id', $asset->tenant_id)
                        ->where('scope', '!=', 'system');
                });
        })
        ->get();
    
    // Filter by category enablement
    $enabledFields = [];
    foreach ($fields as $field) {
        if ($this->isFieldEnabledForCategory($asset, $field->id, $categoryId)) {
            // Get options
            $options = DB::table('metadata_options')
                ->where('metadata_field_id', $field->id)
                ->pluck('value', 'system_label')
                ->toArray();
            
            if (!empty($options)) {
                $enabledFields[] = [
                    'key' => $field->key,
                    'label' => $field->system_label ?? $field->key,
                    'type' => $field->type,
                    'options' => array_values($options),
                ];
            }
        }
    }
    
    return $enabledFields;
}
```

---

## 10. Error Handling & Resilience

### Failure Modes

1. **Plan Limit Exceeded** - Skip gracefully, log, mark as skipped
2. **API Failure** - Log error, mark as failed, don't retry forever
3. **Invalid Response** - Log warning, skip invalid fields, continue with valid ones
4. **Timeout** - Retry with exponential backoff (max 3 tries)

### Never Block Upload

**Critical:** AI metadata generation failures must NEVER affect asset upload success.

**Implementation:**
- All exceptions caught in job
- Job completes successfully even if AI fails
- Errors logged but not thrown
- Asset processing continues normally

---

## 11. Performance & Scalability

### Optimization Strategies

1. **Batch Processing:** Process multiple fields in single API call (current design)
2. **Thumbnail Size:** Use medium thumbnail (1024x1024) for cost/performance balance
3. **Caching:** Cache field definitions per category (avoid repeated DB queries)
4. **Queue Isolation:** Consider dedicated `ai` queue for future scaling
5. **Rate Limiting:** Respect OpenAI rate limits (implement exponential backoff)

### Cost Optimization

1. **Model Selection:** Allow `gpt-4o-mini` for cost-sensitive tenants
2. **Field Batching:** Single API call for all fields (reduces overhead)
3. **Skip Empty Assets:** Don't process assets without category or eligible fields
4. **Idempotency:** Don't regenerate if candidates already exist (unless manual rerun)

---

## 12. Admin Configuration (Future)

### TODO: Admin Company-Wide Settings

**Future Enhancement:** Admin UI on company/tenant settings page to:
- Configure default AI model per tenant
- Enable/disable AI metadata generation per tenant
- Set cost budgets per tenant
- View AI usage and cost reports

**Location:** `/app/admin/companies/{tenant}/ai-settings` (future)

**Implementation:** Deferred to future phase. Document in planning section.

---

## 13. Database Schema Changes

### New Tables

**None required** - Uses existing `asset_metadata_candidates` table with `producer = 'ai'`

### Extensions

**Optional:** `ai_usage_costs` table for detailed cost tracking (see Section 4)

---

## 14. API Endpoints

### New Endpoints

**POST** `/app/assets/{asset}/ai-metadata/regenerate`
- Regenerates AI metadata for an asset
- Requires `assets.ai_metadata.regenerate` permission
- Checks plan limits before execution
- Returns job status

**GET** `/app/assets/{asset}/ai-metadata/status`
- Returns generation status, cost, candidates created
- For admin UI display

---

## 15. Testing Strategy

### Test Philosophy

**Critical Principles:**
- **Never mock OpenAI API in unit tests** - Use integration tests with test API keys or mock responses
- **Test plan limit enforcement** - Hard stops must be verified
- **Test graceful degradation** - AI failures must not block uploads
- **Test cost tracking** - Verify accurate cost attribution
- **Test idempotency** - Auto-generation runs once, manual rerun overrides
- **Test tenant isolation** - Never process assets from other tenants

### Test Structure

**Unit Tests:**
- `AiMetadataGenerationService` - Core logic (prompt building, response parsing)
- `AiMetadataPromptBuilder` - Prompt construction
- `AiMetadataResponseParser` - Response parsing and validation
- Field eligibility logic
- Cost calculation

**Feature Tests:**
- `AiMetadataGenerationJob` - Full job execution with mocked OpenAI
- Plan limit enforcement
- Admin rerun endpoint
- Activity logging
- Error handling

**Integration Tests:**
- End-to-end flow (asset upload → AI generation → suggestions)
- Cost tracking integration
- Activity log verification

### Test Scenarios

#### 1. Happy Path: Successful Generation

**Test:** `test_generates_metadata_successfully`
- Asset with category and thumbnail
- Multiple ai_eligible fields with options
- Valid OpenAI response with high confidence
- Verifies:
  - Candidates created in `asset_metadata_candidates` table
  - `producer = 'ai'` set correctly
  - `_ai_metadata_generated_at` timestamp set
  - Usage tracked in `ai_usage` table
  - Cost tracked (if cost tracking implemented)
  - Activity event logged

**Test:** `test_generates_metadata_for_multiple_fields`
- Asset with 5 ai_eligible fields
- All fields returned in single API call
- Verifies all candidates created correctly

#### 2. Plan Limit Enforcement

**Test:** `test_skips_generation_when_plan_limit_exceeded`
- Tenant at plan limit (`max_ai_tagging_per_month`)
- Attempts to generate metadata
- Verifies:
  - `PlanLimitExceededException` thrown
  - No candidates created
  - No usage tracked
  - Asset marked as skipped in metadata
  - Warning logged

**Test:** `test_allows_generation_when_below_limit`
- Tenant below plan limit
- Verifies generation proceeds normally

**Test:** `test_plan_limit_check_before_api_call`
- Verifies limit checked BEFORE OpenAI API call
- Prevents unnecessary API costs

#### 3. API Failure Handling

**Test:** `test_handles_openai_api_failure_gracefully`
- OpenAI API returns 500 error
- Verifies:
  - Error logged
  - Asset processing continues (no exception thrown)
  - No candidates created
  - Asset marked as failed in metadata

**Test:** `test_handles_openai_timeout`
- OpenAI API times out
- Verifies graceful handling

**Test:** `test_retries_on_transient_failures`
- OpenAI API returns 429 (rate limit)
- Verifies exponential backoff retry (max 3 tries)

#### 4. Invalid Response Handling

**Test:** `test_handles_invalid_json_response`
- OpenAI returns invalid JSON
- Verifies:
  - Error logged
  - No candidates created
  - Asset processing continues

**Test:** `test_handles_missing_confidence_scores`
- Response missing confidence field
- Verifies field skipped (confidence >= 0.90 required)

**Test:** `test_handles_invalid_field_values`
- Response contains value not in allowed_values
- Verifies invalid field skipped, valid fields processed

**Test:** `test_handles_low_confidence_values`
- Response contains confidence < 0.90
- Verifies low-confidence fields skipped

#### 5. Field Eligibility & Filtering

**Test:** `test_skips_when_no_eligible_fields`
- Asset with category but no ai_eligible fields
- Verifies:
  - Job completes without error
  - No API call made
  - No candidates created

**Test:** `test_only_processes_ai_eligible_fields`
- Asset with mix of ai_eligible and non-ai_eligible fields
- Verifies only ai_eligible fields processed

**Test:** `test_respects_category_enablement`
- Field ai_eligible but suppressed for asset's category
- Verifies field not included in prompt

**Test:** `test_skips_fields_without_options`
- Field ai_eligible but no options defined
- Verifies field skipped

**Test:** `test_skips_when_category_missing`
- Asset without category assigned
- Verifies job skips gracefully

#### 6. Cost Tracking

**Test:** `test_tracks_cost_accurately`
- Successful generation
- Verifies:
  - Cost tracked in `ai_usage` table (if extended)
  - Cost matches OpenAI API response
  - Model name tracked
  - Tokens tracked (if available)

**Test:** `test_tracks_cost_per_tenant`
- Multiple tenants
- Verifies costs attributed to correct tenant

**Test:** `test_cost_tracking_on_failure`
- API call fails after cost incurred
- Verifies cost still tracked (if partial cost)

#### 7. Activity Logging

**Test:** `test_logs_generation_event`
- Successful generation
- Verifies:
  - `ASSET_AI_METADATA_GENERATED` event logged
  - Event contains: candidates_created, cost, fields_processed, model
  - Event linked to correct asset and tenant

**Test:** `test_logs_regeneration_event`
- Manual regeneration
- Verifies:
  - `ASSET_AI_METADATA_REGENERATED` event logged
  - Event contains: triggered_by, triggered_at
  - `is_manual_rerun = true` in generation event

**Test:** `test_logs_skip_event`
- Plan limit exceeded
- Verifies skip reason logged

#### 8. Idempotency & Re-runs

**Test:** `test_auto_generation_runs_once`
- Asset already has `_ai_metadata_generated_at` timestamp
- Attempts auto-generation
- Verifies:
  - Job skips (no API call)
  - No new candidates created
  - Info logged

**Test:** `test_manual_rerun_overrides_timestamp`
- Asset already generated
- Manual rerun triggered
- Verifies:
  - API call made
  - New candidates created
  - Timestamp updated
  - Old candidates replaced (or new ones added)

**Test:** `test_manual_rerun_preserves_dismissals`
- Asset has dismissed suggestions
- Manual rerun triggered
- Verifies:
  - `_ai_suggestions_dismissed` array preserved
  - New suggestions don't include dismissed values

#### 9. Admin Rerun Endpoint

**Test:** `test_regenerate_endpoint_requires_permission`
- User without `assets.ai_metadata.regenerate` permission
- Attempts to regenerate
- Verifies 403 response

**Test:** `test_regenerate_endpoint_checks_plan_limits`
- Tenant at plan limit
- Admin attempts regeneration
- Verifies 403 response with error message

**Test:** `test_regenerate_endpoint_dispatches_job`
- Valid request
- Verifies:
  - Job dispatched with `isManualRerun = true`
  - 200 response with success message
  - Activity event logged

#### 10. Concurrent Requests & Transaction Safety

**Test:** `test_concurrent_requests_respect_plan_limits`
- Multiple concurrent requests from same tenant
- Tenant at limit - 1
- Verifies:
  - Only one request succeeds
  - Others fail with plan limit exception
  - Usage count accurate (no race conditions)

**Test:** `test_transaction_safety_on_usage_tracking`
- Concurrent usage tracking
- Verifies no double-counting

#### 11. Tenant Isolation

**Test:** `test_never_processes_other_tenant_assets`
- Tenant A attempts to process Tenant B's asset
- Verifies:
  - Access denied or asset not found
  - No candidates created for wrong tenant
  - Cost not attributed to wrong tenant

#### 12. Prerequisites

**Test:** `test_skips_when_no_thumbnail`
- Asset without `medium_thumbnail_url`
- Verifies:
  - Job skips gracefully
  - Info logged
  - No API call made

**Test:** `test_skips_when_thumbnail_generation_failed`
- Asset with failed thumbnail generation
- Verifies graceful skip

#### 13. Model Selection

**Test:** `test_uses_default_model_gpt_4o_mini`
- No model specified
- Verifies GPT-4o-mini used (default)

**Test:** `test_respects_tenant_model_override`
- Tenant configured to use GPT-4o
- Verifies GPT-4o used

#### 14. Prompt Building

**Test:** `test_prompt_includes_all_eligible_fields`
- Asset with 3 ai_eligible fields
- Verifies prompt contains all 3 fields with options

**Test:** `test_prompt_includes_field_descriptions`
- Verifies descriptions included for better AI understanding

**Test:** `test_prompt_includes_confidence_requirement`
- Verifies prompt instructs AI to only return high-confidence values

#### 15. Response Parsing

**Test:** `test_parses_valid_json_response`
- Valid OpenAI JSON response
- Verifies candidates created correctly

**Test:** `test_validates_field_values_against_options`
- Response contains invalid value
- Verifies invalid value rejected

**Test:** `test_filters_low_confidence_values`
- Response contains confidence < 0.90
- Verifies low-confidence values filtered out

### Test Files to Create

**Unit Tests:**
- `tests/Unit/Services/AiMetadataGenerationServiceTest.php`
- `tests/Unit/Services/AiMetadataPromptBuilderTest.php`
- `tests/Unit/Services/AiMetadataResponseParserTest.php`

**Feature Tests:**
- `tests/Feature/Jobs/AiMetadataGenerationJobTest.php`
- `tests/Feature/AiMetadataRegenerationTest.php`
- `tests/Feature/AiMetadataPlanLimitsTest.php`

**Integration Tests:**
- `tests/Feature/AiMetadataIntegrationTest.php` (end-to-end)

### Test Data Setup

**Factories Needed:**
- Asset factory with category and thumbnail
- Metadata field factory with ai_eligible flag
- Metadata options factory
- Tenant factory with plan limits
- Category factory

**Fixtures:**
- Sample OpenAI API responses (valid and invalid)
- Sample images for testing
- Test API keys (or mocked responses)

### Mocking Strategy

**Mock OpenAI API:**
- Use HTTP client mocking for OpenAI API calls
- Provide realistic response structures
- Test various error scenarios (500, timeout, rate limit)

**Mock Services:**
- `AiUsageService` - Mock for unit tests, real for integration
- `ActivityRecorder` - Mock for unit tests, verify calls

**Database:**
- Use in-memory SQLite for fast tests
- Use transactions for isolation
- Seed test data per test

### Test Coverage Goals

**Minimum Coverage:**
- Service methods: 90%+
- Job handle method: 85%+
- Error paths: 80%+
- Edge cases: 70%+

**Critical Paths (Must Have 100% Coverage):**
- Plan limit enforcement
- Cost tracking
- Tenant isolation
- Dismissal preservation on rerun

---

## 16. Documentation Updates

### Phase Documents to Update

1. **`PHASE_C_METADATA_GOVERNANCE.md`** - Add AI generation section
2. **`AI_USAGE_LIMITS_AND_SUGGESTIONS.md`** - Add cost tracking extension
3. **`PHASE_INDEX.md`** - Add Phase I entry
4. **Create:** `PHASE_I_AI_METADATA_GENERATION.md` (this document)

### Code Comments

- Add comprehensive docblocks to all new services
- Document prompt structure and response format
- Document cost calculation logic
- Add TODO comments for vector DB integration

---

## 17. Implementation Checklist

### Phase I.1: Core Service (Foundation)
- [ ] Create `AiMetadataGenerationService`
- [ ] Implement field eligibility logic
- [ ] Implement prompt building
- [ ] Implement response parsing
- [ ] Add error handling
- [ ] Add `_ai_metadata_generated_at` timestamp tracking (prevents silent re-runs)

### Phase I.2: Job Integration
- [ ] Create `AiMetadataGenerationJob`
- [ ] Integrate into `ProcessAssetJob` chain
- [ ] Add plan limit checks
- [ ] Add prerequisite checks (thumbnail, category)

### Phase I.3: Cost Tracking
- [ ] Extend `AiUsageService` with cost tracking
- [ ] Create `ai_usage_costs` table (optional)
- [ ] Implement cost calculation
- [ ] Add cost to activity logs

### Phase I.4: Admin Rerun
- [ ] Add controller method
- [ ] Add route
- [ ] Add UI button in asset drawer
- [ ] Add permission check
- [ ] Add activity logging

### Phase I.5: Activity Logging
- [ ] Add `ASSET_AI_METADATA_GENERATED` event
- [ ] Add `ASSET_AI_METADATA_REGENERATED` event
- [ ] Log all generation attempts
- [ ] Log costs and usage

### Phase I.6: Testing & Validation
- [ ] Unit tests for `AiMetadataGenerationService` (15+ test cases)
- [ ] Unit tests for prompt building and response parsing
- [ ] Feature tests for `AiMetadataGenerationJob` (20+ test cases)
- [ ] Feature tests for admin rerun endpoint
- [ ] Integration tests for end-to-end flow
- [ ] Test plan limit enforcement (hard stops)
- [ ] Test error handling (graceful degradation)
- [ ] Test cost tracking (accurate attribution)
- [ ] Test idempotency (auto-generation runs once)
- [ ] Test tenant isolation
- [ ] Test dismissal preservation on rerun
- [ ] Test concurrent request safety
- [ ] Achieve 90%+ coverage on critical paths

### Phase I.7: Documentation
- [ ] Update phase documents
- [ ] Add code comments
- [ ] Document API endpoints
- [ ] Document configuration

---

## 18. Future Enhancements (Post-Phase I)

### Vector Database Integration
- Store image embeddings
- Similarity-based context
- Learning from user feedback

### Multi-Provider Support
- Add Gemini provider
- Add Claude provider
- Provider selection per tenant

### Advanced Prompting
- Few-shot learning from similar assets
- User preference learning
- Category-specific prompts

### Batch Processing
- Process multiple assets in single API call (if provider supports)
- Cost optimization for bulk operations

### Admin Configuration UI
- Tenant-level AI settings
- Model selection per tenant
- Cost budget management

---

## 19. Security & Privacy

### Data Handling
- Images sent to OpenAI are temporary (not stored by OpenAI beyond API call)
- No PII in prompts
- Field values are tenant-scoped
- All operations logged for audit

### Permission Enforcement
- Admin rerun requires explicit permission
- Plan limits enforced at job level
- Tenant isolation maintained

---

## 20. Cost Estimates

### Important: OpenAI Vision Pricing Model

**⚠️ Critical Understanding:** OpenAI Vision pricing is **NOT token-based for images**. 

OpenAI Vision pricing is:
- **Image-based:** Fixed cost per image based on resolution tier
- **Resolution-based:** Different tiers (low, standard, high)
- **Model-dependent:** Different models have different image pricing
- **NOT proportional to base64 size:** Images are not tokenized like text

**Cost Calculation:**
- Actual costs are returned by the OpenAI API response
- Do NOT hardcode cost assumptions in code
- Track actual costs returned by provider
- Use provider's cost calculation methods

### Estimated Per-Asset Costs (Approximate)

**GPT-4o:**
- Image analysis: ~$0.01-0.05 per image (resolution-dependent)
- Text prompt/response: ~$0.001-0.01 (token-based)
- **Estimated total:** ~$0.01-0.06 per asset (varies by image size and prompt complexity)

**GPT-4o-mini:**
- Image analysis: ~$0.0001-0.001 per image (much cheaper)
- Text prompt/response: ~$0.0001-0.001 (token-based)
- **Estimated total:** ~$0.0002-0.002 per asset (significantly cheaper)

**⚠️ These are rough estimates. Always track actual costs returned by the provider API.**

### Monthly Cost Projections (Rough Estimates)

**100 assets/month (Starter plan):**
- GPT-4o: ~$1-6/month (varies by image resolution)
- GPT-4o-mini: ~$0.02-0.20/month (much more cost-effective)

**Recommendation:** **Default to GPT-4o-mini.** The cost difference is substantial (10-100x cheaper) while still providing good quality for most metadata fields.

---

## Summary

This phase implements enterprise-grade AI metadata generation that:

1. ✅ Uses best-in-class models (GPT-4o/GPT-4o-mini)
2. ✅ Respects plan limits with hard stops
3. ✅ Tracks costs accurately
4. ✅ Provides admin rerun capability
5. ✅ Logs all activity
6. ✅ Only processes ai_eligible fields
7. ✅ Never blocks uploads on failure
8. ✅ Structured for future vector DB integration
9. ✅ Follows enterprise best practices
10. ✅ Fully documented

**Status:** Ready for implementation after approval.

---

## Recommendations & Best Practices

### Model Selection Strategy

**Recommendation:** **Default to GPT-4o-mini. GPT-4o is opt-in.**
- **Cost:** Significantly cheaper (10-100x depending on image resolution)
- **Quality:** Sufficient for most metadata fields (photo_type, usage_rights, etc.)
- **Upgrade Path:** Allow admin override to GPT-4o for accuracy-critical use cases
- **Margin Protection:** Defaulting to cheaper model protects margins while maintaining quality

**Configuration:** Make model configurable per tenant (future admin UI)

### Prompt Engineering

**Best Practices:**
1. **Structured Output:** Use OpenAI's JSON mode for reliable parsing
2. **Few-Shot Examples:** Include 2-3 examples in prompt for better accuracy
3. **Field Descriptions:** Provide clear descriptions for each field
4. **Option Clarity:** List all allowed values explicitly
5. **Confidence Threshold:** Enforce >= 0.90 in prompt instructions

### Cost Management

**Strategies:**
1. **Batch Fields:** Single API call for all fields (current design)
2. **Thumbnail Optimization:** Use medium (1024x1024) not large (4096x4096)
3. **Skip Logic:** Don't process if no eligible fields or category missing
4. **Idempotency:** Don't regenerate if candidates already exist (unless manual)
5. **Model Selection:** Default to cost-effective model, allow upgrade

### Error Handling

**Principles:**
1. **Never Block Upload:** All failures are logged but don't throw
2. **Graceful Degradation:** If AI fails, asset processing continues normally
3. **Retry Logic:** Exponential backoff for transient failures (max 3 tries)
4. **Plan Limits:** Hard stop, no retry, skip gracefully
5. **Invalid Responses:** Parse what's valid, skip invalid fields, continue

### Performance Optimization

**Future Enhancements:**
1. **Caching:** Cache field definitions per category (reduce DB queries)
2. **Queue Isolation:** Dedicated `ai` queue for better scaling
3. **Batch Processing:** Process multiple assets in single call (if provider supports)
4. **Async Processing:** Don't block upload pipeline
5. **Rate Limiting:** Respect provider rate limits with exponential backoff

### Security Considerations

**Requirements:**
1. **Tenant Isolation:** Never process assets from other tenants
2. **Permission Checks:** Admin rerun requires explicit permission
3. **Audit Trail:** All operations logged with full context
4. **Data Privacy:** Images sent to OpenAI are temporary (not stored by OpenAI)
5. **Cost Attribution:** Accurate cost tracking per tenant prevents cross-tenant billing

### Monitoring & Observability

**Metrics to Track:**
1. **Generation Success Rate:** % of assets successfully processed
2. **Average Cost per Asset:** Track cost trends (use actual provider costs)
3. **Field Accuracy:** User acceptance rate of suggestions per field (future - see TODO below)
4. **API Latency:** Response times for optimization
5. **Error Rates:** Categorize failures (plan limits, API errors, parsing errors)

**TODO (Future - Acceptance Rate Metric):**
- Track % of suggestions accepted per field
- This becomes the most valuable AI quality signal
- Example: If `photo_type` suggestions are accepted 90% of the time but `usage_rights` only 30%, we know which fields need prompt tuning
- No implementation now - just reserve the concept for future analytics

**Logging:**
- All generation attempts (success and failure)
- Cost per operation
- Token usage
- Model used
- Fields processed
- Error details

---

## Implementation Plan

### Phase I.1: Core Service (Critical Path)
**Priority:** HIGH  
**Dependencies:** None  
**Estimated Time:** 2-3 days  
**Deliverables:**
- `AiMetadataGenerationService` with prompt building and response parsing
- Field eligibility logic
- Error handling
- `_ai_metadata_generated_at` timestamp tracking

**Implementation Steps:**
1. Create `AiMetadataGenerationService` class
2. Implement `getEligibleFields()` method (query ai_eligible fields, filter by category)
3. Implement `buildPrompt()` method (construct structured prompt with field definitions)
4. Implement `generateMetadata()` method (orchestrate API call and candidate creation)
5. Implement `parseResponse()` method (validate and parse OpenAI response)
6. Add error handling (try/catch, logging)
7. Add timestamp tracking (`_ai_metadata_generated_at`)
8. Write unit tests (15+ test cases)
9. Code review and refinement

**Files to Create:**
- `app/Services/AiMetadataGenerationService.php`
- `tests/Unit/Services/AiMetadataGenerationServiceTest.php`

### Phase I.2: Job Integration (Critical Path)
**Priority:** HIGH  
**Dependencies:** Phase I.1  
**Estimated Time:** 1-2 days  
**Deliverables:**
- `AiMetadataGenerationJob`
- Integration into `ProcessAssetJob` chain
- Plan limit enforcement
- Prerequisite checks (thumbnail, category)

**Implementation Steps:**
1. Create `AiMetadataGenerationJob` class
2. Add constructor with `isManualRerun` flag
3. Implement idempotency check (`_ai_metadata_generated_at`)
4. Add plan limit check (before API call)
5. Add prerequisite checks (thumbnail, category)
6. Integrate service call
7. Add error handling (never throw, always log)
8. Integrate into `ProcessAssetJob` chain (after thumbnail generation)
9. Write feature tests (20+ test cases)
10. Code review and refinement

**Files to Create:**
- `app/Jobs/AiMetadataGenerationJob.php`
- `tests/Feature/Jobs/AiMetadataGenerationJobTest.php`

**Files to Modify:**
- `app/Jobs/ProcessAssetJob.php` (add job dispatch)

### Phase I.3: Cost Tracking (Important - Optional Table)
**Priority:** MEDIUM  
**Dependencies:** Phase I.1, I.2  
**Deliverables:**
- Cost tracking extension to `AiUsageService` (extend `ai_usage` table with nullable cost columns)
- Cost calculation logic (use actual provider costs, don't hardcode)
- Cost attribution in activity logs
- **Optional:** `ai_usage_costs` table for detailed analytics (defer if not needed)

### Phase I.4: Admin Rerun (Nice to Have)
**Priority:** MEDIUM  
**Dependencies:** Phase I.1, I.2  
**Estimated Time:** 1 day  
**Deliverables:**
- Controller method and route
- UI button in asset drawer
- Permission checks
- Activity logging

**Implementation Steps:**
1. Add `regenerateAiMetadata()` method to `AssetController`
2. Add permission check (`assets.ai_metadata.regenerate`)
3. Add plan limit check
4. Dispatch job with `isManualRerun = true`
5. Add route (`POST /app/assets/{asset}/ai-metadata/regenerate`)
6. Add UI button in asset drawer/modal
7. Add activity logging
8. Write feature tests
9. Code review and refinement

**Files to Modify:**
- `app/Http/Controllers/AssetController.php` (or create `AiMetadataController.php`)
- `routes/web.php`
- `resources/js/Components/AssetDrawer.jsx` (or similar component)
- `tests/Feature/AiMetadataRegenerationTest.php`

### Phase I.5: Activity Logging (Important)
**Priority:** MEDIUM  
**Dependencies:** Phase I.2  
**Estimated Time:** 0.5 days  
**Deliverables:**
- New event types (`ASSET_AI_METADATA_GENERATED`, `ASSET_AI_METADATA_REGENERATED`)
- Comprehensive logging
- Audit trail

**Implementation Steps:**
1. Add event types to `EventType` enum
2. Add logging in `AiMetadataGenerationJob` (success and failure)
3. Add logging in `regenerateAiMetadata()` controller method
4. Verify activity events appear in activity logs
5. Write tests for activity logging
6. Code review

**Files to Modify:**
- `app/Enums/EventType.php`
- `app/Jobs/AiMetadataGenerationJob.php`
- `app/Http/Controllers/AssetController.php` (or `AiMetadataController.php`)
- `tests/Feature/AiMetadataRegenerationTest.php` (add activity log tests)

---

## Future Planning Ideas

### Vector Database Integration (Phase I.6 - Future)

**Purpose:** Enable similarity-based context and learning

**Components:**
- Image embedding generation (store in `asset_embeddings` table)
- Similarity search (find similar assets)
- Context injection (include similar assets' metadata in prompts)
- User feedback loop (learn from acceptances/rejections)

**Benefits:**
- Better accuracy through context
- Learning from user behavior
- Batch optimization (similar images processed together)

**Implementation:** Deferred to future phase. Add TODO comments in code.

### Multi-Provider Support (Phase I.7 - Future)

**Purpose:** Provider diversification and cost optimization

**Components:**
- Gemini provider implementation
- Claude provider implementation
- Provider selection per tenant
- Fallback logic (if primary provider fails)

**Benefits:**
- Cost optimization (choose cheapest provider)
- Reliability (fallback if one provider down)
- Feature comparison (use best provider for each use case)

### Admin Configuration UI (Phase I.8 - Future)

**Purpose:** Tenant-level AI settings management

**Components:**
- Admin settings page (`/app/admin/companies/{tenant}/ai-settings`)
- Model selection per tenant
- Cost budget management
- Feature toggles (enable/disable AI generation)
- Usage and cost reports

**Benefits:**
- Tenant control over AI usage
- Cost transparency
- Feature customization

### Field Type Differentiation (Phase I.9 - Future)

**Purpose:** Better management of different metadata field types

**Components:**
- Admin UI to categorize fields (system-determined vs AI-suggested vs manual)
- Better visual distinction in metadata management UI
- Type-specific configuration options

**Location:** Admin company-wide tenant page (future)

**Benefits:**
- Clearer understanding of field behavior
- Better configuration UX
- Reduced confusion

---

---

## Quick Reference for Developers

### Key Files to Create

**Services:**
- `app/Services/AiMetadataGenerationService.php` - Core service
- `app/Services/AiMetadataPromptBuilder.php` - Prompt construction (optional, can be in service)
- `app/Services/AiMetadataResponseParser.php` - Response parsing (optional, can be in service)

**Jobs:**
- `app/Jobs/AiMetadataGenerationJob.php` - Background job

**Controllers:**
- `app/Http/Controllers/AiMetadataController.php` (or extend `AssetController.php`)

**Migrations:**
- `database/migrations/YYYY_MM_DD_HHMMSS_add_cost_tracking_to_ai_usage.php` (Phase I.3)

**Tests:**
- `tests/Unit/Services/AiMetadataGenerationServiceTest.php`
- `tests/Feature/Jobs/AiMetadataGenerationJobTest.php`
- `tests/Feature/AiMetadataRegenerationTest.php`
- `tests/Feature/AiMetadataPlanLimitsTest.php`
- `tests/Feature/AiMetadataIntegrationTest.php`

### Key Dependencies

**Required Services:**
- `AiUsageService` - Plan limit enforcement and usage tracking
- `AiMetadataSuggestionService` - Existing suggestion system (consumes candidates)
- `ActivityRecorder` - Activity logging

**Required Models:**
- `Asset` - Asset being processed
- `Tenant` - For plan limits
- `Category` - For field enablement
- `MetadataField` - Field definitions
- `MetadataOption` - Field options

**Required Tables:**
- `asset_metadata_candidates` - Stores AI-generated candidates (existing)
- `ai_usage` - Usage tracking (existing, extend for costs)
- `metadata_fields` - Field definitions (existing)
- `metadata_options` - Field options (existing)

### Critical Rules (DO NOT VIOLATE)

1. **Never block uploads** - All AI failures must be graceful
2. **Plan limits are hard stops** - Check before API call, skip if exceeded
3. **Only ai_eligible fields** - Never process system-determined fields
4. **Auto-generation runs once** - Check `_ai_metadata_generated_at` timestamp
5. **Manual rerun preserves dismissals** - Never clear `_ai_suggestions_dismissed`
6. **Track actual costs** - Use provider API response, don't hardcode
7. **Tenant isolation** - Never process other tenant's assets
8. **Default to GPT-4o-mini** - Cost efficiency first, accuracy second

### Testing Checklist

Before marking any phase complete, verify:

- [ ] All unit tests pass (90%+ coverage on services)
- [ ] All feature tests pass (job execution, endpoints)
- [ ] Plan limit enforcement tested (hard stops work)
- [ ] Error handling tested (graceful degradation)
- [ ] Cost tracking tested (accurate attribution)
- [ ] Idempotency tested (auto-generation runs once)
- [ ] Tenant isolation tested (no cross-tenant processing)
- [ ] Dismissal preservation tested (manual rerun doesn't clear)
- [ ] Activity logging tested (all events logged)
- [ ] Integration test passes (end-to-end flow)

### Common Pitfalls to Avoid

1. **Don't hardcode costs** - Always use actual provider API response
2. **Don't skip plan limit check** - Must check before API call
3. **Don't throw exceptions** - AI failures must not block uploads
4. **Don't process system fields** - Only ai_eligible fields
5. **Don't regenerate automatically** - Check timestamp first
6. **Don't clear dismissals** - Preserve on manual rerun
7. **Don't assume image tokenization** - OpenAI Vision pricing is resolution-based
8. **Don't forget tenant isolation** - Always verify tenant_id

### Implementation Order

**Week 1:**
- Day 1-2: Phase I.1 (Core Service) + Unit Tests
- Day 3: Phase I.2 (Job Integration) + Feature Tests
- Day 4: Phase I.3 (Cost Tracking) + Tests
- Day 5: Phase I.4 (Admin Rerun) + Tests

**Week 2:**
- Day 1: Phase I.5 (Activity Logging) + Tests
- Day 2-3: Integration Testing & Bug Fixes
- Day 4: Documentation Updates
- Day 5: Code Review & Refinement

### Success Criteria

Phase I is complete when:

1. ✅ AI metadata generation works for ai_eligible fields
2. ✅ Plan limits enforced with hard stops
3. ✅ Costs tracked accurately per tenant
4. ✅ Admin can manually regenerate metadata
5. ✅ All activity logged correctly
6. ✅ 90%+ test coverage on critical paths
7. ✅ No uploads blocked by AI failures
8. ✅ Documentation complete and accurate

---

## Recent Implementations (January 2025)

### AI Tag Suggestions Feature

**Status:** ✅ COMPLETE

**Implementation Date:** January 2025

**Overview:**
Extended the AI metadata generation system to include general descriptive tags alongside structured field values. Tags are stored as candidates and displayed in the asset drawer with accept/dismiss controls.

**Components Added:**

1. **Backend Endpoints** (`AssetMetadataController.php`):
   - `getTagSuggestions()` - Returns tag candidates from `asset_tag_candidates` table
   - `acceptTagSuggestion()` - Creates tag in `asset_tags` with `source='ai'` and marks candidate as resolved
   - `dismissTagSuggestion()` - Marks candidate as dismissed to prevent reappearing

2. **Frontend Component** (`AiTagSuggestionsInline.jsx`):
   - Displays AI-suggested tags with confidence indicators
   - Accept button creates tag in `asset_tags` table
   - Dismiss button marks candidate as dismissed
   - Permission-gated using `metadata.suggestions.*` permissions
   - Integrated into `AssetDrawer` component

3. **Database Table** (`asset_tag_candidates`):
   - Stores AI-generated tags as candidates (not approved data)
   - Columns: `id`, `asset_id`, `tag`, `source`, `confidence`, `producer`, `resolved_at`, `dismissed_at`
   - Similar structure to `asset_metadata_candidates` for consistency

**Key Features:**
- Tags are stored as candidates until user accepts them
- Accepted tags are searchable via `asset_tags` table
- Dismissed tags are hidden permanently for that asset
- All actions are permission-protected
- Confidence scores preserved for quality tracking

**Routes Added:**
- `GET /app/assets/{asset}/tags/suggestions`
- `POST /app/assets/{asset}/tags/suggestions/{candidateId}/accept`
- `POST /app/assets/{asset}/tags/suggestions/{candidateId}/dismiss`

---

### Filter Query Fixes

**Status:** ✅ COMPLETE

**Issue:** Filters were not finding assets with AI-accepted or manual-override metadata values because the filter query only checked `source IN ('user', 'system')`.

**Fix Applied:**
Updated `MetadataFilterService::applyFieldFilter()` to include all approved sources:
- Added `'ai'` source for AI-accepted suggestions
- Added `'manual_override'` source for manually overridden values

**Files Modified:**
- `app/Services/MetadataFilterService.php` (lines 136, 143)

**Result:**
- Assets with AI-accepted Photo Type values are now filterable
- Assets with manual-override values are now filterable
- All approved metadata values are searchable regardless of source

---

### UI Improvements

**Status:** ✅ COMPLETE

**Changes Made:**

1. **AI Suggestion Acceptance Display** (`AssetDetailsModal.jsx`):
   - Changed "Approved" text to "AI suggestion accepted" when `source === 'ai'`
   - Preserves AI origin in display text

2. **Badge Logic** (`AssetDetailsModal.jsx`):
   - Updated `getSourceBadge()` to check `producer === 'ai'` or `confidence !== null` to show AI badge
   - Ensures AI-accepted suggestions show "AI" badge even if source was temporarily set to 'user'

3. **Backend Source Preservation** (`AssetMetadataController.php`):
   - When accepting AI suggestions, preserve `source = 'ai'` and set `producer = 'ai'`
   - Ensures AI origin is maintained in database

**Files Modified:**
- `resources/js/Components/AssetDetailsModal.jsx`
- `app/Http/Controllers/AssetMetadataController.php`

---

### Cost Tracking Verification

**Status:** ✅ VERIFIED

**Implementation:**
Costs are being recorded through dual tracking:

1. **`ai_agent_runs` table:**
   - `estimated_cost` is set via `$agentRun->markAsSuccessful()`
   - Includes tokens_in, tokens_out, and cost

2. **`ai_usage` table:**
   - Tracked via `AiUsageService->trackUsageWithCost()`
   - Records cost per tenant, usage type, and date

**Display:**
- `/app/companies` page shows AI cost estimates
- Combines costs from both `ai_agent_runs` and `ai_usage` tables
- Displays `current_month_cost`, `current_month_calls`, `agent_runs`, `agent_cost`, etc.

**Files Verified:**
- `app/Http/Controllers/CompanyController.php` (uses `CompanyCostService`)
- `app/Services/CompanyCostService.php` (calculates AI agent costs)
- `app/Jobs/AiMetadataGenerationJob.php` (tracks usage and cost)

---

### Field Eligibility Optimization

**Status:** ✅ COMPLETE

**Issue:** When Photo Type already had an approved value, regenerating AI tags would still send it to OpenAI, wasting API calls.

**Fix Applied:**
Updated `AiMetadataGenerationService::getEligibleFields()` to check if fields already have approved values before including them in the AI inference plan.

**Logic Added:**
```php
// Check if field already has an approved value
$hasApprovedValue = DB::table('asset_metadata')
    ->where('asset_id', $asset->id)
    ->where('metadata_field_id', $field->id)
    ->whereNotNull('approved_at')
    ->exists();

if ($hasApprovedValue) {
    continue; // Skip this field - don't send to OpenAI
}
```

**Result:**
- Fields with existing approved values are skipped during AI generation
- Saves API costs by avoiding unnecessary calls
- Works for both manually set and previously accepted AI suggestions

**Files Modified:**
- `app/Services/AiMetadataGenerationService.php`

---

### Signed S3 URL Fix

**Status:** ✅ COMPLETE

**Issue:** OpenAI Vision API was receiving local URLs (`http://jackpot.local/...`) which it couldn't access, causing "Error while downloading" errors.

**Fix Applied:**
Updated `Asset::getMediumThumbnailUrlAttribute()` to generate signed S3 URLs instead of local route URLs.

**Implementation:**
- Changed from `route('assets.thumbnail.final', ...)` to `Storage::disk('s3')->temporaryUrl()`
- URLs are valid for 1 hour (sufficient for OpenAI to download)
- Works in both local (MinIO) and production (AWS S3) environments

**Files Modified:**
- `app/Models/Asset.php`

**Result:**
- OpenAI can now successfully download images for analysis
- No more "Error while downloading" errors
- Works consistently across environments

---

## Remaining TODOs

### High Priority

1. **Dimensions Display in Asset Drawer**
   - **Status:** ⚠️ IN PROGRESS
   - **Issue:** Dimensions not showing in asset drawer file info
   - **Requirement:** Should be a readonly system field pulled from original image dimensions
   - **Note:** Some file types may not provide dimensions
   - **Action Needed:** Verify if dimensions are stored, if not, implement as computed metadata similar to color_space/orientation

2. **Filter Visibility for Available Values**
   - **Status:** ⚠️ PARTIAL
   - **Issue:** Filters showing "no available values" when values exist
   - **Current State:** Filter query works, but available values calculation may need review
   - **Action Needed:** Verify available values query includes all approved sources ('user', 'system', 'ai', 'manual_override')

3. **System Automated Fields UI**
   - **Status:** 📋 PLANNED
   - **Requirement:** Subtle way to show system automated fields (less prominent styling)
   - **Requirement:** Upload checkbox should be greyed out for automated fields
   - **Requirement:** Allow disabling system fields per category (e.g., videos don't want color profile)
   - **Action Needed:** Update UI in metadata management page

### Medium Priority

4. **Job Error Surfacing**
   - **Status:** 📋 PLANNED
   - **Requirement:** Surface job errors to site_engineer, site_admin, or site_owner roles
   - **Requirement:** Allow expanding collapsed errors from AI agent runs
   - **Decision Needed:** Wait for now or implement? (User requested recommendation)

5. **AI Skipped State on Asset**
   - **Status:** 📋 OPTIONAL
   - **Enhancement:** Set `asset.metadata._ai_metadata_status = "skipped:thumbnail_unavailable"` for better debugging
   - **Note:** Already logging skips, this would add explicit state

6. **Vector Database Integration**
   - **Status:** 📋 FUTURE
   - **Requirement:** Store image embeddings for similarity-based suggestions
   - **Requirement:** Reference previously selected images for better suggestions
   - **Note:** Deferred to future phase

### Low Priority / Future Enhancements

7. **Acceptance Rate Metrics**
   - **Status:** 📋 FUTURE
   - **Enhancement:** Track % of suggestions accepted per field
   - **Value:** Most valuable AI quality signal
   - **Note:** Reserve concept, no implementation yet

8. **Better Type Management on Admin Page**
   - **Status:** 📋 FUTURE
   - **Requirement:** Better outlining of different population types (manual, automated, AI-suggested)
   - **Requirement:** Manageability on admin company-wide tenant page
   - **Note:** Future enhancement

9. **Metadata Field Name Alignment**
   - **Status:** ⚠️ PARTIAL
   - **Issue:** Names may not be aligned across all metadata displays
   - **Action Needed:** Verify and align field names consistently

10. **Category Select in Modal**
    - **Status:** ✅ COMPLETE (reverted per user request)
    - **Note:** Category select moved to edit/add new field modal (not on main page)

---

## Implementation Notes

### AI Tag Candidates Table

**Migration:** `2026_01_25_000003_create_asset_tag_candidates_table.php`

**Purpose:** Store AI-generated tags as candidates before user approval

**Structure:**
- `id` (primary key)
- `asset_id` (UUID, foreign key to assets)
- `tag` (string)
- `source` ('ai' or 'user')
- `confidence` (decimal, nullable)
- `producer` ('ai' or 'user', nullable)
- `resolved_at` (timestamp, nullable)
- `dismissed_at` (timestamp, nullable)
- `timestamps`

**Indexes:** `asset_id`, `tag`, `(asset_id, tag)`, `source`, `producer`, `resolved_at`, `dismissed_at`

---

### Unified Vision API Call

**Implementation:** Single Vision API call returns both structured fields and general tags

**Response Format:**
```json
{
  "fields": {
    "photo_type": { "value": "studio", "confidence": 0.94 }
  },
  "tags": [
    { "value": "beer", "confidence": 0.91 },
    { "value": "dramatic", "confidence": 0.88 }
  ]
}
```

**Rules:**
- Fields must match `allowed_values` exactly
- Tags: lowercase, singular, no punctuation
- Omit anything below confidence threshold

**Benefits:**
- One API call instead of multiple
- Reduced costs
- Faster processing

---

### Cost Tracking

**Dual Tracking System:**
1. `ai_agent_runs` table - Tracks individual agent runs with cost
2. `ai_usage` table - Tracks aggregate usage and costs per tenant

**Display:**
- `/app/companies` shows combined AI costs
- Includes all AI agents (metadata_generator, tagging, etc.)
- Tenant-specific estimates displayed

---

**End of Phase I Planning Document**
