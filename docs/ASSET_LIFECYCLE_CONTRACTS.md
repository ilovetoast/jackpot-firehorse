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

- This file (merged **Phase L** appendix below) — publication and archival details
- `/docs/MEDIA_PIPELINE.md` - Processing pipeline details
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


---

# Merged phase reference: asset publication and archival

The following section preserves `PHASE_L_ASSET_PUBLICATION_AND_ARCHIVAL.md` in full.

## Source: PHASE_L_ASSET_PUBLICATION_AND_ARCHIVAL.md


**Status:** ✅ **COMPLETE & STABLE**  
**Last Updated:** January 2025  
**All Sub-Phases:** L.1 through L.6.3 Complete

---

## Purpose

This phase introduces business-level lifecycle states for assets:

- **Published** — whether an asset is approved and visible for general use
- **Archived** — whether an asset is retired from active use but retained for recovery

These states are **orthogonal to**:
- Asset processing
- Thumbnail generation
- AI metadata
- AssetStatus visibility enum

---

## Core Design Principles

1. **Visibility ≠ Publishing** — AssetStatus controls UI visibility, publication controls business approval
2. **Processing ≠ Approval** — Technical readiness does not imply business approval
3. **Archive ≠ Delete** — Archiving preserves data, deletion removes it
4. **No enum explosion** — Use nullable timestamps instead of status enums
5. **Derived state over explicit state** — State is derived from field presence, not stored as enum

---

## Lifecycle Axes Overview

| Axis | Purpose | Storage |
|------|---------|---------|
| **AssetStatus** | UI visibility | enum (VISIBLE/HIDDEN/FAILED) |
| **Processing pipeline** | Technical readiness | jobs + flags (thumbnail_status, metadata flags) |
| **Publication** | Business approval | `published_at` (nullable timestamp) |
| **Archival** | Storage & retention | `archived_at` (nullable timestamp) |

---

## Publication Lifecycle

### Fields

- `published_at` — nullable timestamp
- `published_by_id` — nullable foreign key → `users` table

### Derived State

- **Unpublished** → `published_at IS NULL`
- **Published** → `published_at IS NOT NULL`

### Notes

- No enum used
- No metadata field
- Publishing is reversible (set `published_at` to `NULL` to unpublish)

---

## Archival Lifecycle

### Fields

- `archived_at` — nullable timestamp
- `archived_by_id` — nullable foreign key → `users` table

### Derived State

- **Active** → `archived_at IS NULL`
- **Archived** → `archived_at IS NOT NULL`

### Notes

- Archive is reversible (set `archived_at` to `NULL` to restore)
- Asset is never deleted (soft deletes only)
- Intended for S3 storage class changes (future enhancement)

---

## Relationship to AssetStatus

| Scenario | AssetStatus | published_at | archived_at |
|----------|-------------|--------------|--------------|
| Normal published asset | `VISIBLE` | ✅ (not null) | ❌ (null) |
| Pending approval | `HIDDEN` | ❌ (null) | ❌ (null) |
| Archived asset | `HIDDEN` | (any) | ✅ (not null) |
| Failed asset | `FAILED` | (any) | (any) |

**Key Points:**
- AssetStatus remains the UI gate
- Publication and archival explain **why** the asset is hidden
- Multiple lifecycle states can coexist (e.g., published but archived)

---

## What This Phase Explicitly Does NOT Do

❌ **Does not:**
- Add approval workflows
- Add category rules
- Add UI components
- Modify processing jobs
- Modify metadata confidence logic
- Add enums

Those belong to subsequent phases.

---

## Completed Work

### ✅ Phase L.1 — Database Schema & Model

**Database Migration:**
- `published_at` — nullable timestamp
- `published_by_id` — nullable foreign key to `users`
- `archived_at` — nullable timestamp
- `archived_by_id` — nullable foreign key to `users`
- Indexes on `published_at` and `archived_at` for query performance

**Asset Model Updates:**
- `$fillable` updated with lifecycle fields
- `$casts` updated with datetime casts for timestamps
- Relationships added:
  - `publishedBy()` — BelongsTo User
  - `archivedBy()` — BelongsTo User
- Helper methods added:
  - `isPublished()` — returns `true` if `published_at` is not null
  - `isArchived()` — returns `true` if `archived_at` is not null

### ✅ Phase L.2 — Publish / Unpublish Services

**Services:**
- `AssetPublicationService` — handles publish/unpublish logic
- Permission checks via `AssetPolicy`
- Activity event logging
- State management (published_at, published_by_id, status transitions)

**Endpoints:**
- `POST /app/assets/{id}/publish` — publish an asset
- `POST /app/assets/{id}/unpublish` — unpublish an asset

**Permissions:**
- `asset.publish` — required to publish assets
- `asset.unpublish` — required to unpublish assets

### ✅ Phase L.3 — Archive / Restore Services

**Services:**
- `AssetArchiveService` — handles archive/restore logic
- S3 storage class transitions (STANDARD ↔ STANDARD_IA)
- Permission checks via `AssetPolicy`
- Activity event logging
- Graceful S3 failure handling (non-blocking)

**Endpoints:**
- `POST /app/assets/{id}/archive` — archive an asset
- `POST /app/assets/{id}/restore` — restore an asset

**Permissions:**
- `asset.archive` — required to archive assets
- `asset.restore` — required to restore assets

**S3 Integration:**
- Archiving transitions objects to `STANDARD_IA` (Infrequent Access)
- Restoring transitions objects back to `STANDARD`
- Applies to main asset file + all thumbnails
- Failures are logged but do not block the operation

### ✅ Phase L.4 — Read-Only Lifecycle UI

**Components:**
- `AssetDrawer` — shows lifecycle badges (Unpublished, Archived)
- `AssetDetailsModal` — displays full lifecycle information with dates and users
- Lifecycle status indicators throughout the UI

**UI Principles:**
- Asset Drawer: Minimal, status-only (shows only exceptional states)
- Asset Modal: Verbose, actionable (shows full lifecycle details)

### ✅ Phase L.5 — Category-Based Approval Rules

**Implementation:**
- Categories can have `requires_approval` flag
- Assets uploaded to approval-required categories are automatically unpublished
- `UploadCompletionService` checks category rules and sets initial state
- Integration with existing category system

### ✅ Phase L.6 — Approval Actions & Inbox

**L.6.1 — Backend Approval Endpoints:**
- Reuses existing publish/unpublish endpoints
- Authorization enforced by `AssetPolicy`
- Returns JSON responses with asset state updates

**L.6.2 — Approval Inbox UI:**
- Filter for pending publication assets (`lifecycle=pending_approval` or `lifecycle=unpublished`)
- Visible only to users with `asset.publish` permission
- Asset cards show publish actions when in pending mode
- Optimistic UI updates on publish/unpublish

**L.6.3 — Email Notifications:**
- `AssetPendingApproval` event dispatched on upload
- `SendAssetPendingApprovalNotification` listener (queued)
- Emails sent to users with `asset.publish` permission
- Excludes uploader from notifications
- Non-blocking (failures logged, don't break upload flow)

---

## Verified Features

### ✅ Core Functionality
- **Published/Unpublished lifecycle** — timestamps and permissions working correctly
- **Archived/Restored lifecycle** — timestamps + S3 storage class transitions verified
- **Pending publication visibility rules** — proper filtering and permissions enforced
- **Approval inbox UI** — streamlined publish workflow implemented
- **Lifecycle actions** — Publish, Unpublish, Archive, Restore all functional
- **Email notifications** — approvers notified when assets need publication
- **Category-based approval gate** — integration working as designed
- **Activity events** — all lifecycle changes logged correctly

### ✅ Test Coverage
- **14 archive service tests** — all passing
- **S3 mocking** — properly implemented (no real AWS calls)
- **Permission enforcement** — verified in tests
- **State preservation** — confirmed in tests
- **Error handling** — validated (S3 failures don't block operations)

### ✅ Architectural Wins
- **No enum explosion** — using existing `AssetStatus` and timestamps
- **No metadata misuse** — lifecycle state in dedicated columns
- **Clear separation** — Visibility ≠ Publication ≠ Archive
- **Backend as source of truth** — UI reflects backend state
- **Consistent language** — "Publish/Unpublish" throughout (no "Approve" for assets)

---

## Guardrails

1. **Publishing must never be inferred from processing completion**
   - Publication is a business decision, not a technical state
   - Processing jobs must never set `published_at`

2. **Archiving must never delete data**
   - Archiving preserves assets for recovery
   - Use soft deletes, never hard deletes

3. **Metadata fields must never represent lifecycle state**
   - Lifecycle state is stored in dedicated timestamp fields
   - Metadata fields are for asset properties, not workflow state

4. **AssetStatus must remain visibility-only**
   - AssetStatus controls UI visibility (VISIBLE/HIDDEN/FAILED)
   - Lifecycle states (published/archived) are separate concerns
   - Do not conflate visibility with publication or archival

---

## Implementation Notes

### Model Helper Methods

```php
// Check publication state
$asset->isPublished(); // returns bool

// Check archival state
$asset->isArchived(); // returns bool
```

### Querying Lifecycle States

```php
// Published assets
Asset::whereNotNull('published_at')->get();

// Unpublished assets
Asset::whereNull('published_at')->get();

// Archived assets
Asset::whereNotNull('archived_at')->get();

// Active (not archived) assets
Asset::whereNull('archived_at')->get();
```

### Relationships

```php
// Get user who published the asset
$asset->publishedBy; // User|null

// Get user who archived the asset
$asset->archivedBy; // User|null
```

---

## UI Language & Filtering

### Filter Terminology

The system uses consistent language throughout:

- **"Pending Publication"** — Assets that are unpublished + HIDDEN status (requires `asset.publish` permission)
- **"Unpublished"** — All unpublished assets, both VISIBLE and HIDDEN (requires `metadata.bypass_approval` permission)
- **"Archived"** — Assets with `archived_at` set (requires `asset.archive` permission)

### Filter Locations

All lifecycle filters are located in the **"More filters"** section:
- Pending Publication (`lifecycle=pending_approval`)
- Unpublished (`lifecycle=unpublished`)
- Archived (`lifecycle=archived`)

Filters are mutually exclusive (only one can be active at a time).

### UI Components

**Asset Drawer (Minimal):**
- Shows "Unpublished" badge when `published_at IS NULL` and not archived
- Shows "Archived" badge when `archived_at IS NOT NULL`
- "Publish" button visible when unpublished and not archived (requires `asset.publish`)

**Asset Details Modal (Verbose):**
- Shows full lifecycle information with dates and users
- Actions dropdown includes: Publish, Unpublish, Archive, Restore
- Actions are permission-gated and conditionally displayed

---

## Services & Policies

### AssetPublicationService

Handles publish/unpublish operations:
- Permission checks via `AssetPolicy`
- Sets `published_at` and `published_by_id`
- Updates `AssetStatus` (VISIBLE/HIDDEN)
- Logs activity events
- Preserves published state when archiving

### AssetArchiveService

Handles archive/restore operations:
- Permission checks via `AssetPolicy`
- Sets `archived_at` and `archived_by_id`
- Updates `AssetStatus` to HIDDEN when archiving
- Restores visibility based on publication state when restoring
- Transitions S3 objects to STANDARD_IA (archive) or STANDARD (restore)
- Gracefully handles S3 failures (logs but doesn't block)
- Logs activity events

### AssetPolicy

Enforces authorization for lifecycle actions:
- Checks tenant-level permissions (`hasPermissionForTenant`)
- Falls back to brand-level permissions (`hasPermissionForBrand`)
- Verifies user is assigned to asset's brand
- Blocks actions on FAILED assets
- Allows tenant owners/admins as fallback

---

## Events & Notifications

### Events

- `AssetPendingApproval` — dispatched when asset uploaded to category requiring approval
- `ASSET_ARCHIVED` — logged via ActivityRecorder
- `ASSET_UNARCHIVED` — logged via ActivityRecorder

### Email Notifications

- **Trigger:** `AssetPendingApproval` event
- **Recipients:** Users with `asset.publish` permission (tenant + brand scoped)
- **Excludes:** Asset uploader (even if they have permission)
- **Delivery:** Queued (non-blocking)
- **Content:** Asset details, category, uploader, CTA link to pending publication view

---

## Testing

### Test Suite

**Location:** `tests/Unit/Services/AssetArchiveServiceTest.php`

**Coverage:**
- Basic archive/restore operations
- Idempotency (safe to call multiple times)
- Permission enforcement
- Failed asset protection
- State preservation (published state maintained when archiving)
- S3 storage class transitions (mocked, no real AWS calls)
- S3 failure handling (operations succeed despite S3 errors)

**All 14 tests passing** ✅

---

## Constraints Respected

- ✅ AssetStatus enum unchanged
- ✅ Processing pipeline untouched
- ✅ Metadata system untouched
- ✅ No new enums introduced
- ✅ No workflow engine added
- ✅ No assignment logic added

---

**End of Phase L Documentation**
