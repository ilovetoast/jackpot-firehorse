# Asset Lifecycle Contracts

**Last Updated**: 2026-01-28  
**Status**: Active Documentation

This document defines the canonical contracts for asset lifecycle, publication, approval, and processing pipeline behavior. These contracts are enforced by tests and must not be violated without updating both code and tests.

---

## Table of Contents

1. [Publication vs Approval](#publication-vs-approval)
2. [State Matrix Source of Truth](#state-matrix-source-of-truth)
3. [Pipeline Trigger Guarantees](#pipeline-trigger-guarantees)
4. [Assets vs Deliverables Consistency](#assets-vs-deliverables-consistency)

---

## Publication vs Approval

### Core Principle

**Publication and Approval are SEPARATE concerns:**

- **Publication** = Visibility (`published_at !== null`)
- **Approval** = Governance (`approval_status`, `approved_at`)

These states are **independent**. An asset can be:
- Published without approval (if approval not required)
- Unpublished with pending approval
- Published with pending approval (published first, then approval workflow added)
- Published and approved

### Canonical Rules

1. **Publication is determined ONLY by `is_published`** (derived from `published_at !== null`)
   - UI badges, visibility, downloadability all use `is_published`
   - Approval status does NOT affect publication state
   - Published assets appear in default grids regardless of approval status

2. **Approval status does NOT affect visibility**
   - Published assets with `approval_status = 'pending'` are still visible
   - Published assets with `approval_status = 'rejected'` are still visible
   - Only `is_published === false` hides assets from default views

3. **Default Publication Behavior**
   - By default (no approval switches enabled): ALL assets are published immediately on creation
   - This applies to both Assets and Deliverables
   - Approval workflows are EXPLICIT and OPT-IN

### Brand Switches (Global, Not Per Type)

Two brand-level switches control behavior:

1. **`require_asset_approval`** (default: `false`)
   - `false` → Assets & Deliverables publish immediately
   - `true` → Assets & Deliverables are unpublished on create, explicit publish required

2. **`require_metadata_approval`** (default: `false`)
   - `false` → Metadata edits apply immediately
   - `true` → Metadata edits require approval
   - Applies to all asset types

**CRITICAL**: There must be NO divergence between Assets and Deliverables for these switches.

### State Matrix

See `/resources/js/utils/assetStateMatrix.js` for the canonical state matrix helper.

**Badge Labels** (priority order):
1. Archived (`archived_at !== null`)
2. Expired (`expires_at < now`)
3. Unpublished (`is_published === false`)
4. Pending Approval (`approval_status === 'pending'` + approvals enabled)
5. Rejected (`approval_status === 'rejected'` + approvals enabled)

**Button Visibility**:
- Publish: `!is_published && !archived_at`
- Unpublish: `is_published && !archived_at`
- Review & Approve: `approval_status === 'pending'` + user is approver
- Resubmit: `approval_status === 'rejected'`

---

## State Matrix Source of Truth

### Location

**Frontend**: `/resources/js/utils/assetStateMatrix.js`

This utility provides:
- `getAssetStateMatrix(asset, auth)` - Returns canonical state (badges, buttons)
- `shouldAppearInDefaultGrid(asset)` - Visibility check
- `shouldAppearOnHomepage(asset)` - Homepage visibility check
- `isDownloadable(asset)` - Downloadability check

### Rules

1. **All UI logic MUST use `asset.is_published`** (not `published_at`, not approval status)
2. **Badge logic MUST use the state matrix helper** (or match its logic exactly)
3. **Button visibility MUST use the state matrix helper** (or match its logic exactly)
4. **Visibility checks MUST use `is_published` only** (approval status is separate)

### API Contract

Backend controllers (`AssetController`, `DeliverableController`) MUST include:
```php
'is_published' => $asset->published_at !== null, // Canonical boolean
```

This ensures frontend always has the correct publication state.

---

## Pipeline Trigger Guarantees

### Event → Listener → Job Chain

**Guaranteed Flow**:
1. `UploadCompletionService::complete()` → dispatches `AssetUploaded` event
2. `ProcessAssetOnUpload` listener → receives event, dispatches `ProcessAssetJob`
3. `ProcessAssetJob` → chains downstream jobs:
   - `PopulateAutomaticMetadataJob` (dominant colors)
   - `ResolveMetadataCandidatesJob`
   - `AITaggingJob`
   - `AiMetadataGenerationJob`
   - `AiTagAutoApplyJob`
   - `AiMetadataSuggestionJob`
   - `FinalizeAssetJob`
   - `PromoteAssetJob`

### Guarantees

1. **Every asset upload triggers the pipeline**
   - `AssetUploaded` event is ALWAYS dispatched on upload completion
   - Listener is ALWAYS registered (via Laravel event system)
   - `ProcessAssetJob` is ALWAYS dispatched (unless listener fails)

2. **Pipeline is idempotent**
   - `ProcessAssetJob` checks `processing_started` metadata flag
   - Prevents duplicate processing chains
   - Safe to retry on failure

3. **Pipeline health is observable**
   - Logging available via `PIPELINE_DEBUG=true` env var
   - Regression test: `ProcessingPipelineHealthTest`
   - Tests verify event → listener → job chain works

### Debugging

Enable pipeline health logs:
```bash
# In .env
PIPELINE_DEBUG=true
```

Or via config:
```php
// config/app.php
'pipeline_debug' => env('PIPELINE_DEBUG', false),
```

Logs are gated to reduce production noise while maintaining observability during development.

---

## Assets vs Deliverables Consistency

### Hard Rule

**Assets and Deliverables MUST behave identically** for:
- Publication lifecycle
- Visibility rules
- Filter behavior
- Category scoping
- Brand scoping
- Approval workflows
- Default publication state

### Enforcement

1. **Tests enforce consistency**
   - `AssetDeliverableLifecycleConsistencyTest` - Compares behavior side-by-side
   - `ApprovalFlowTest` - Verifies identical approval behavior
   - `IsPublishedFlagTest` - Verifies identical publication state exposure

2. **Shared services**
   - `LifecycleResolver` - Single source of truth for visibility rules
   - `UploadCompletionService` - Single source of truth for publication defaults
   - `AssetPublicationService` - Single source of truth for publish/unpublish actions

3. **No type-specific branching**
   - Controllers use same services
   - Services don't branch by `AssetType`
   - Tests fail if behavior diverges

### If Behavior Diverges

1. **Test will fail** - Consistency tests explicitly compare both types
2. **Identify the divergence** - Compare controller/service logic
3. **Fix by sharing logic** - Don't add special cases, share the canonical path
4. **Update tests** - Ensure tests prevent future divergence

---

## Related Documentation

- `/docs/PHASE_L_ASSET_PUBLICATION_AND_ARCHIVAL.md` - Publication phase details
- `/docs/THUMBNAIL_PIPELINE.md` - Processing pipeline details
- `/tests/Feature/AssetDeliverableLifecycleConsistencyTest.php` - Consistency tests
- `/tests/Feature/ApprovalFlowTest.php` - Approval flow tests
- `/tests/Feature/ProcessingPipelineHealthTest.php` - Pipeline health tests

---

## Change Log

- **2026-01-28**: Initial contract documentation created
  - Publication vs Approval separation
  - State matrix source of truth
  - Pipeline trigger guarantees
  - Assets vs Deliverables consistency rules
