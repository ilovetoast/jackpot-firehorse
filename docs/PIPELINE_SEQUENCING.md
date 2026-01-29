# Pipeline Sequencing Architecture

**Last Updated**: 2026-01-28  
**Status**: Active Documentation

This document defines the sequencing guarantees for the asset processing pipeline, specifically the relationship between thumbnail generation and image-derived jobs.

---

## Canonical Invariant

**NO image-derived job may run until**:
- `thumbnail_status === ThumbnailStatus::COMPLETED`
- OR a source image is confirmed readable (future enhancement)

This invariant prevents:
- Dominant color extraction failures (needs image access)
- AI image analysis failures (needs image access)
- Metadata derivation failures (needs image access)

---

## Thumbnail Readiness Signal

**Canonical Signal**: `thumbnail_status === ThumbnailStatus::COMPLETED`

- Set by `GenerateThumbnailsJob` on successful completion
- Checked by all image-derived jobs before processing
- Enforced via explicit gates in job handlers

**Alternative Signals** (not currently used):
- `thumbnails_ready_at` timestamp (future enhancement)
- `has_thumbnails` boolean flag (future enhancement)
- Derived file existence check (future enhancement)

---

## Two Models for Handling Thumbnail Dependencies

### Option A — "Retry Until Ready" (Current Direction)

**Best when**:
- Thumbnails are guaranteed to complete eventually
- You want work to resume automatically
- The job chain should be self-healing

**Implementation**:
- Job checks `thumbnail_status` at start
- If not ready, calls `release(delay)` to reschedule
- Job retries automatically until thumbnails are ready
- No manual intervention required

**Current Usage**:
- `PopulateAutomaticMetadataJob` — releases and retries

**Example**:
```php
if ($asset->thumbnail_status !== ThumbnailStatus::COMPLETED) {
    $this->release(60); // Retry in 60 seconds
    return;
}
```

### Option B — "Skip + Re-trigger on Completion"

**Best when**:
- Thumbnail job explicitly dispatches downstream work
- You want explicit orchestration
- Downstream jobs should never retry themselves

**Implementation**:
- Job checks `thumbnail_status` at start
- If not ready, marks as skipped and exits
- `GenerateThumbnailsJob` dispatches downstream jobs on completion
- Thumbnail job is the orchestrator

**Current Usage**:
- `AITaggingJob` — skips and marks as skipped

**Example**:
```php
if ($asset->thumbnail_status !== ThumbnailStatus::COMPLETED) {
    $this->markAsSkipped($asset, 'thumbnail_unavailable');
    return;
}
```

---

## Current State (2026-01-28)

**Mixed Models** (intentional, documented):
- `AITaggingJob` → Option B (skip)
- `PopulateAutomaticMetadataJob` → Option A (retry)

**Why Both Exist**:
- Historical evolution of the pipeline
- Both models are valid and functional
- No immediate need to standardize

**Long-term Recommendation**:
- Standardize on **Option A (Retry Until Ready)** for consistency
- Benefits: Self-healing, automatic recovery, simpler orchestration
- Migration: Update `AITaggingJob` to use `release()` instead of skip

---

## Why This Issue Surfaced Now

**Context**: Pipeline sequencing regression discovered during system maturity phase.

**Root Cause**:
- Thumbnail readiness was **assumed**, not **enforced**
- Jobs ran before thumbnails were ready, causing failures

**Why It Broke Now**:
1. **Pipeline became observable** — Logging exposed the sequencing issue
2. **Lifecycle unified** — Jobs and lifecycle logic consolidated
3. **Accidental sequencing removed** — Hidden dependencies surfaced

**This is Normal System Evolution**:
- Hidden assumptions surface as systems mature
- Sequencing bugs appear when dependencies become explicit
- Fixing them makes the system stronger than before

**This is NOT instability** — it's a sign of crossing from "it works" to "it's correct".

---

## Enforcement

### Jobs with Thumbnail Gates

1. **AITaggingJob**
   - Model: Option B (skip)
   - Gate: `thumbnail_status !== COMPLETED` → skip
   - Status: Enforced ✅

2. **PopulateAutomaticMetadataJob**
   - Model: Option A (retry)
   - Gate: `thumbnail_status !== COMPLETED` → release(60)
   - Status: Enforced ✅

3. **AiMetadataGenerationJob**
   - Model: Wait logic (retries internally)
   - Gate: Waits up to 30 seconds for thumbnails
   - Status: Enforced ✅

### Tests

**`PipelineSequencingTest`** verifies:
- Jobs skip/release when thumbnails not ready
- Jobs run normally when thumbnails are ready
- Gates exist in code (code inspection)

---

## Related Documentation

- `/docs/ASSET_LIFECYCLE_CONTRACTS.md` — Lifecycle contracts
- `/docs/THUMBNAIL_PIPELINE.md` — Thumbnail generation details
- `/tests/Feature/PipelineSequencingTest.php` — Sequencing tests

---

## Change Log

- **2026-01-28**: Initial sequencing documentation
  - Defined canonical invariant
  - Documented two models (Option A vs Option B)
  - Explained why issue surfaced now
  - Added enforcement gates to `PopulateAutomaticMetadataJob`
