# Phase 3.1 — Downloader System Foundations

**Status:** 🔨 IN PROGRESS (Step 2)  
**Date Started:** 2024

---

## Overview

Phase 3.1 establishes the foundation for the downloader system. This phase creates the database schema and Eloquent models for Download Groups that support snapshot and living downloads, asset relationships, lifecycle-based cleanup, and plan-based expiration rules.

**This phase does NOT implement:**
- ZIP file generation
- S3 object operations
- Queue jobs
- Controllers or routes
- UI components

---

## Step 1: Models, Relationships, and Lifecycle Foundations

### Database Schema

#### `downloads` Table

**Primary Key:** `id` (UUID)

**Core Fields:**
- `tenant_id` - Foreign key to tenants table
- `created_by_user_id` - Foreign key to users table (nullable)
- `download_type` - Enum: `'snapshot'` | `'living'`
- `source` - Enum: `'grid'` | `'drawer'` | `'collection'` | `'public'` | `'admin'`
- `title` - String (nullable, renamable on top tiers)
- `slug` - String (stable public ID, unique per tenant)
- `version` - Integer (default: 1, increments on invalidation)

**Status Tracking:**
- `status` - Enum: `'pending'` | `'ready'` | `'invalidated'` | `'failed'`
- `zip_status` - Enum: `'none'` | `'building'` | `'ready'` | `'invalidated'` | `'failed'`

**ZIP File Information:**
- `zip_path` - String (nullable, S3 key only)
- `zip_size_bytes` - Unsigned big integer (nullable)

**Lifecycle Management:**
- `expires_at` - Timestamp (nullable, plan-based expiration)
- `deleted_at` - Timestamp (nullable, soft delete)
- `hard_delete_at` - Timestamp (nullable, calculated at creation)

**Configuration:**
- `download_options` - JSON (nullable, additional options)
- `access_mode` - Enum: `'public'` | `'team'` | `'restricted'` (default: `'team'`)
- `allow_reshare` - Boolean (default: `true`)

**Timestamps:**
- `created_at`
- `updated_at`

**Indexes:**
- `tenant_id`
- `created_by_user_id`
- `deleted_at`
- `hard_delete_at`
- `status`
- `zip_status`
- `download_type`
- Unique: `['tenant_id', 'slug']`

#### `download_asset` Pivot Table

**Fields:**
- `id` - Primary key
- `download_id` - UUID, foreign key to downloads
- `asset_id` - UUID, foreign key to assets
- `is_primary` - Boolean (default: `false`, marks primary asset)
- `created_at`
- `updated_at`

**Constraints:**
- Foreign key: `download_id` → `downloads.id` (cascade delete)
- Foreign key: `asset_id` → `assets.id` (cascade delete)
- Unique: `['download_id', 'asset_id']`

**Indexes:**
- `download_id`
- `asset_id`
- `is_primary`

---

## Download Types

### Snapshot Downloads

- **Asset list is immutable** after creation
- Asset relationships cannot be modified once created
- ZIP represents exact state at creation time
- Suitable for historical records, exports, backups

### Living Downloads

- **Asset list is mutable** over time
- Assets can be added or removed after creation
- ZIP must be regenerated when assets change
- Suitable for dynamic collections, press kits

---

## Lifecycle Philosophy

### Creation Flow

1. Download group created with `status = 'pending'`
2. Assets attached to download group
3. Status transitions to `'ready'`
4. ZIP generation can be triggered (future phase)

### Soft Delete

- User removes download from UI
- Sets `deleted_at` timestamp
- ZIP file remains in S3 until cleanup
- Download group hidden from normal queries

### Hard Delete

- Calculated at creation time based on:
  - `download_type` (snapshot vs living)
  - Tenant plan/tier
  - `expires_at` value (if set)
- Stored in `hard_delete_at` field
- Lifecycle job (future phase) will:
  - Delete ZIP from S3 when `hard_delete_at` reached
  - Permanently delete download record

### Expiration Rules (Design Only)

**Example Policies (NOT IMPLEMENTED):**

- **Snapshot + Free Plan:**
  - `expires_at` = `now() + 7 days`
  - `hard_delete_at` = `expires_at + grace_window`

- **Snapshot + Pro Plan:**
  - `expires_at` = `now() + 30 days`
  - `hard_delete_at` = `expires_at + grace_window`

- **Living + Enterprise:**
  - No `expires_at` (infinite)
  - `hard_delete_at` = `null` (manual deletion only)

**Note:** Actual expiration calculation will be implemented in a service in a future phase.

---

## Deletion Strategy

### Soft Delete → Hard Delete Flow

1. **User Action:** User removes download
   - `deleted_at` set
   - ZIP remains in S3

2. **Lifecycle Job (Future Phase):**
   - Queries downloads where `hard_delete_at <= now()`
   - Deletes ZIP from S3 (`zip_path`)
   - Permanently deletes download record

### Database + S3 Cleanup

- Download record tracks S3 ZIP location (`zip_path`)
- Lifecycle job ensures S3 cleanup before DB deletion
- Prevents orphaned S3 objects

---

## Analytics & AI-Support Fields

### Per-Asset Download Counts

The `download_asset` pivot table enables:
- Query: "How many downloads include asset X?"
- Aggregate: "Which assets are downloaded most frequently?"
- Track: "Download trends over time per asset"

### Living Downloads Impact

For living downloads:
- Metrics change over time as assets are added/removed
- `version` field tracks invalidation/regeneration events
- Historical analytics can track download composition changes

### Source Tracking

The `source` field enables:
- "Downloads initiated from grid view"
- "Downloads from public press-kit pages"
- Source-based analytics and reporting

---

## Relationships

### Download → Tenant
- Belongs to Tenant (cascade delete)

### Download → User
- Created by User (set null on delete)

### Download → Assets
- Many-to-many via `download_asset` pivot
- Includes `is_primary` pivot flag

### Asset → Downloads
- Many-to-many via `download_asset` pivot
- Enables "Which downloads include this asset?" queries

---

## Eloquent Model

### Download Model

**Location:** `app/Models/Download.php`

**Traits:**
- `HasUuids` - UUID primary keys
- `SoftDeletes` - Soft delete support

**Key Methods:**
- `isSnapshot()` - Check if snapshot download
- `isLiving()` - Check if living download
- `isExpired()` - Check expiration status
- `isReadyForHardDelete()` - Check if ready for cleanup
- `hasZip()` - Check if ZIP exists
- `zipNeedsRegeneration()` - Check if ZIP needs rebuild
- `calculateHardDeleteAt()` - Document lifecycle calculation (not implemented)

**Scopes:**
- `scopeSnapshots()` - Filter snapshot downloads
- `scopeLiving()` - Filter living downloads
- `scopeReadyForHardDelete()` - Downloads ready for cleanup
- `scopeExpired()` - Expired downloads
- `scopeWithReadyZip()` - Downloads with ready ZIPs
- `scopeNeedsZipRegeneration()` - Downloads needing ZIP rebuild

---

## Enums

### DownloadStatus
- `PENDING` - Initial state
- `READY` - Ready for use
- `INVALIDATED` - Asset list changed (living)
- `FAILED` - Creation/processing failed

### DownloadType
- `SNAPSHOT` - Immutable asset list
- `LIVING` - Mutable asset list

### ZipStatus
- `NONE` - No ZIP generated
- `BUILDING` - ZIP generation in progress
- `READY` - ZIP ready for download
- `INVALIDATED` - ZIP needs regeneration
- `FAILED` - ZIP generation failed

### DownloadSource
- `GRID` - Asset grid view
- `DRAWER` - Asset drawer/detail
- `COLLECTION` - Collection view
- `PUBLIC` - Public press-kit page
- `ADMIN` - Admin interface

### DownloadAccessMode
- `PUBLIC` - Anyone with link
- `TEAM` - Team members only
- `RESTRICTED` - Specific users only

---

## Explicit Non-Goals

Phase 3.1 Step 1 explicitly does **NOT** include:

- ❌ ZIP file generation logic
- ❌ S3 object creation/deletion
- ❌ Queue jobs for ZIP building
- ❌ Controllers or routes
- ❌ API endpoints
- ❌ UI components
- ❌ Download lifecycle cleanup jobs
- ❌ Expiration calculation service
- ❌ Plan-based rule engine

These are reserved for future steps/phases.

---

## Future Hosted Pages

The `slug` field (unique per tenant) enables future hosted press-kit pages:
- Public URL: `/{tenant_slug}/downloads/{download_slug}`
- Stable, shareable links
- Access controlled by `access_mode` field

**Note:** Hosted page UI is not implemented in this phase.

---

## Migration Notes

### Migration Files

1. `2026_01_15_114903_create_downloads_table.php`
   - Creates downloads table with all required fields
   - Includes indexes and constraints

2. `2026_01_15_114904_create_download_asset_table.php`
   - Creates pivot table linking downloads to assets
   - Includes unique constraint and indexes

### Running Migrations

```bash
./vendor/bin/sail artisan migrate
```

---

## Related Documentation

- `docs/PHASE_2_UPLOAD_SYSTEM.md` - Upload system (locked)
- `docs/PHASE_2_5_OBSERVABILITY_LOCK.md` - Error observability (locked)

---

---

## Step 2: Lifecycle State Machine & Expiration Policy

### Lifecycle Policy Definitions

#### A. Snapshot Downloads

**Lifecycle Rules:**
- `expires_at` is **required** (except top-tier overrides)
- `hard_delete_at` = `expires_at` + grace window
- ZIP status transitions: `NONE → BUILDING → READY`
- ZIP is **immutable** once `READY` (cannot regenerate)
- Asset list is immutable after creation

**State Machine:**
```
CREATE → status=PENDING
  ↓
ASSETS_ATTACHED → status=READY
  ↓
ZIP_GENERATION → zip_status=BUILDING
  ↓
ZIP_COMPLETE → zip_status=READY (immutable)
  ↓
USER_DELETE → deleted_at set (ZIP remains)
  ↓
HARD_DELETE_TIME → hard_delete_at reached → S3 cleanup + DB delete
```

#### B. Living Downloads

**Lifecycle Rules:**
- `expires_at` may be **null** (top tiers allow unlimited)
- ZIP is **invalidated** on asset change
- ZIP status transitions: `READY → INVALIDATED → BUILDING → READY` (reversible)
- `hard_delete_at` only set on soft delete (if expires_at is null)
- Asset list is **mutable** (can add/remove assets)

**State Machine:**
```
CREATE → status=PENDING
  ↓
ASSETS_ATTACHED → status=READY
  ↓
ZIP_GENERATION → zip_status=BUILDING
  ↓
ZIP_COMPLETE → zip_status=READY
  ↓
ASSET_CHANGE → zip_status=INVALIDATED (regenerate ZIP)
  ↓
ZIP_REGENERATION → zip_status=BUILDING → READY (cycle repeats)
  ↓
USER_DELETE → deleted_at set (ZIP remains)
  ↓
HARD_DELETE_TIME → hard_delete_at reached → S3 cleanup + DB delete
```

---

### Expiration Rules (Design Only)

**DownloadExpirationPolicy Service** (stub methods, no implementation yet)

#### Expiration Periods

**Snapshot Downloads:**
- **Free Plan:** `expires_at` = `now() + 7 days`
- **Pro Plan:** `expires_at` = `now() + 30 days`
- **Enterprise Plan:** `expires_at` = `null` (unlimited) OR `now() + 365 days`

**Living Downloads:**
- **Free Plan:** `expires_at` = `now() + 7 days`
- **Pro Plan:** `expires_at` = `now() + 30 days`
- **Enterprise Plan:** `expires_at` = `null` (unlimited)

#### Hard Delete Calculation

**If `expires_at` is set:**
- `hard_delete_at` = `expires_at` + grace window
- **Grace windows:**
  - Free Plan: 3 days
  - Pro Plan: 7 days
  - Enterprise Plan: 14 days

**If `expires_at` is null (unlimited):**
- `hard_delete_at` = `null` (manual deletion only)
- Exception: If download is soft-deleted with unlimited expiration:
  - `hard_delete_at` = `deleted_at` + grace window

---

### State Transition Helpers

**Pure helper methods** (no side effects, no DB writes, no S3 access):

#### `shouldExpire(): bool`
- Checks if download should have expiration based on plan rules
- Snapshot downloads: Usually require expiration (except enterprise)
- Living downloads: May not expire on top tiers

#### `shouldHardDelete(): bool`
- Checks if download should be hard deleted
- Returns `true` if `hard_delete_at` is set and in the past
- Returns `true` if soft-deleted and past grace period

#### `canRegenerateZip(): bool`
- Checks if ZIP can be regenerated
- Snapshot downloads with READY ZIP: `false` (immutable)
- Living downloads: `true` (can regenerate)
- Failed downloads: `false` (cannot regenerate)

#### `shouldInvalidateZipOnAssetChange(): bool`
- Checks if ZIP should be invalidated when assets change
- Snapshot downloads: `false` (asset list is immutable)
- Living downloads with READY ZIP: `true` (invalidate and regenerate)

---

### DownloadExpirationPolicy Service

**Location:** `app/Services/DownloadExpirationPolicy.php`

**Stub Methods (Design Only):**

- `calculateExpiresAt(Tenant $tenant, DownloadType $downloadType): ?Carbon`
  - Determines expiration timestamp based on plan and type
  - Returns `null` for unlimited downloads

- `calculateHardDeleteAt(Download $download, ?Carbon $expiresAt): ?Carbon`
  - Calculates hard delete timestamp
  - Considers grace windows and plan rules

- `getGraceWindowDays(Tenant $tenant): int`
  - Returns grace window in days for tenant's plan

- `shouldBeUnlimited(Tenant $tenant, DownloadType $downloadType): bool`
  - Checks if download should have unlimited expiration

- `isExpiresAtRequired(Tenant $tenant, DownloadType $downloadType): bool`
  - Determines if expiration is required for download type and plan

**Note:** All methods are stubs that return placeholders. Actual implementation will be in a future phase.

---

### AI / Analytics Intent

#### Active Download Definition

A download is considered "active" when:
- `status` = `READY` (not `PENDING`, `INVALIDATED`, or `FAILED`)
- `deleted_at` = `null` (not soft-deleted)
- `expires_at` is either `null` or in the future (not expired)

#### Lifecycle Events (Future Implementation)

Lifecycle events should be emitted when:
- Download created (`download.created`)
- Download status changed (`download.status_changed`)
- ZIP generation started (`download.zip_building`)
- ZIP generation completed (`download.zip_ready`)
- ZIP invalidated (`download.zip_invalidated`)
- Assets added/removed (`download.assets_changed`)
- Download expired (`download.expired`)
- Download soft-deleted (`download.deleted`)
- Download hard-deleted (`download.hard_deleted`)

**Note:** Event emission is not implemented in this phase. This is documentation for future phases.

#### Per-Asset Download Counts

Per-asset download counts will be calculated by:
- Querying `download_asset` pivot table
- Filtering by active downloads only (`status = READY`, not soft-deleted, not expired)
- Aggregating by `asset_id`

**Living Downloads Impact:**
- Download counts change over time as assets are added/removed
- Historical counts can be tracked via `version` field increments
- Analytics should consider `version` for trend analysis

---

---

## Step 3: ZIP Build & Cleanup Jobs

### ZIP Build Job

**Location:** `app/Jobs/BuildDownloadZipJob.php`

**Purpose:** Builds ZIP files for download groups by streaming asset files from S3, creating a ZIP archive, and uploading it directly to S3.

**Responsibilities:**
- Accepts a Download ID
- Verifies download exists and status allows ZIP build
- Checks if `zipNeedsRegeneration() === true` or ZIP doesn't exist
- Streams asset files from S3
- Creates ZIP archive (handles duplicate filenames)
- Uploads ZIP directly to S3 (no app disk persistence)
- Updates download model:
  - `zip_status` → `READY`
  - `zip_path` (S3 key: `downloads/{download_id}/download.zip`)
  - `zip_size_bytes`
- Deletes old ZIP from S3 if it exists and is different
- On failure: Sets `zip_status` → `FAILED`

**Safety Rules:**
- Never deletes asset files
- Never blocks user requests (runs in background)
- Job is idempotent (can be retried safely)
- Uses best-effort deletion patterns for old ZIPs
- Handles missing assets gracefully (skips and continues)

**ZIP File Naming:**
- S3 Path: `downloads/{download_id}/download.zip`
- Duplicate filenames in ZIP are handled by prefixing with index (`file_1.ext`, `file_2.ext`)

**Error Handling:**
- Individual asset download failures: Logged and skipped (best effort)
- ZIP creation failures: Job fails, `zip_status` set to `FAILED`
- S3 upload failures: Job fails with exception, retry logic applies

---

### ZIP Invalidation

**Living Downloads:**
- Asset list changes invalidate ZIP
- ZIP status transitions: `READY → INVALIDATED`
- Version increments to track asset list changes
- `invalidateZipIfNeeded()` method on Download model handles this
- Should be called when assets are added/removed from living downloads

**Snapshot Downloads:**
- ZIP is immutable once `READY`
- Asset list is immutable (assets cannot be added/removed)
- ZIP cannot be regenerated for snapshots

**Implementation:**
- `Download::invalidateZipIfNeeded()` method:
  - Checks if download is living (snapshots return false)
  - Checks if ZIP status is `READY` (if not, returns false)
  - Sets `zip_status` to `INVALIDATED`
  - Increments `version` field
  - Saves model

**Note:** Asset change detection and automatic invalidation will be implemented in a future phase (e.g., via model observers or events). For now, `invalidateZipIfNeeded()` must be called explicitly when assets are modified.

---

### Cleanup Job

**Location:** `app/Jobs/CleanupExpiredDownloadsJob.php`

**Purpose:** Cleans up expired downloads by deleting ZIP files from S3 and permanently removing download records.

**Responsibilities:**
- Finds downloads where `shouldHardDelete() === true`
- Processes downloads in batches (50 per batch)
- For each download:
  - Deletes ZIP from S3 (if `zip_path` exists)
  - Permanently deletes download record from database (force delete)
- Uses best-effort deletion patterns (continues on individual failures)

**Safety Rules:**
- **Never deletes asset files** (only ZIP files)
- Never blocks user requests (runs in background)
- Job is idempotent
- Individual download failures are logged but don't stop batch processing
- ZIP deletion failures are non-fatal (logged as warnings)

**Error Handling:**
- Missing storage bucket: Logged as warning, ZIP deletion skipped
- ZIP doesn't exist in S3: Logged as debug, continues
- S3 deletion failures: Logged as warning, continues (best effort)
- Database deletion failures: Logged as error, continues with next download

**Deletion Strategy:**
1. Query downloads where `hard_delete_at <= now()`
2. For each download:
   - Verify `shouldHardDelete()` returns true (double-check)
   - Delete ZIP from S3 (best-effort)
   - Permanently delete download record (force delete, bypasses soft delete)

---

### Failure Handling

**ZIP Build Failures:**
- Job retries up to 3 times with exponential backoff (60s, 300s, 900s)
- On permanent failure, `zip_status` set to `FAILED`
- Download remains in database, can be manually retried later
- Failed ZIP builds can be retriggered if download status allows

**Cleanup Failures:**
- Job retries up to 3 times with exponential backoff
- Individual download cleanup failures don't stop batch processing
- ZIP deletion failures are logged but non-fatal
- Failed cleanups can be retried by running the job again

---

### Job Queue Configuration

**BuildDownloadZipJob:**
- Queue: Default queue (configurable)
- Tries: 3 attempts
- Backoff: [60, 300, 900] seconds
- Timeout: Should be set based on expected ZIP size

**CleanupExpiredDownloadsJob:**
- Queue: Default queue (configurable)
- Tries: 3 attempts
- Backoff: [60, 300, 900] seconds
- Batch size: 50 downloads per chunk

**Recommended Scheduling:**
- `CleanupExpiredDownloadsJob`: Run daily (e.g., via Laravel scheduler)
- `BuildDownloadZipJob`: Dispatched on-demand when ZIP generation is requested

---

### Explicit Non-Goals

Phase 3.1 Step 3 explicitly does **NOT** include:

- ❌ Automatic ZIP invalidation on asset changes (manual call required)
- ❌ ZIP download endpoints/controllers
- ❌ UI for triggering ZIP builds
- ❌ Real-time ZIP build status updates
- ❌ ZIP file serving/delivery logic
- ❌ Asset lifecycle validation (assumes assets exist and are accessible)

These are reserved for future steps/phases.

---

---

## Step 4: Download Delivery & Access Endpoints

### ZIP Download Endpoint

**Route:** `GET /downloads/{download}/download`

**Location:** `app/Http/Controllers/DownloadController.php`

**Purpose:** Provides safe, permission-aware endpoint for downloading ZIP files from download groups.

**Responsibilities:**
- Validate download exists and is `READY`
- Validate ZIP status is `READY`
- Validate access based on `access_mode`:
  - `PUBLIC`: Anyone with link can access
  - `TEAM`: Only authenticated users who are members of the tenant
  - `RESTRICTED`: Only specific users (currently stubbed as team access)
- Check if download is expired
- Generate short-lived S3 signed URL (10 minutes expiration)
- Redirect user to S3 URL (does NOT proxy file)
- Log download intent (analytics placeholder)

**Access Validation:**
- Public downloads: Accessible without authentication
- Team downloads: Requires authenticated user who belongs to download's tenant
- Restricted downloads: Currently treated as team access (future implementation needed)

**Error Handling:**
- 404: Download not found, ZIP path missing
- 403: Access denied (insufficient permissions)
- 410: Download expired
- 422: Download not ready, ZIP not ready, or invalid status
- 500: Failed to generate signed URL

**Error Messages:**
- Download status errors: "Download is not ready yet", "Download has been invalidated", "Download failed"
- ZIP status errors: "ZIP file has not been generated yet", "ZIP file is being built", "ZIP file needs to be regenerated", "ZIP file generation failed"

**Signed URL Strategy:**
- URLs expire after 10 minutes
- Generated using `Storage::disk('s3')->temporaryUrl()`
- Direct redirect to S3 (no proxying through application)
- No S3 paths exposed directly in responses

---

### Single File Download Endpoint

**Route:** `GET /assets/{asset}/download`

**Location:** `app/Http/Controllers/AssetController.php::download()`

**Purpose:** Provides direct download for individual assets (drawer view). No Download Group created.

**Force Download Behavior:**
- Phase 3.1 Step 6 Fix: Downloads are forced via `ResponseContentDisposition: attachment` header
- Signed S3 URLs include Content-Disposition to ensure browser downloads files instead of previewing
- Filename is preserved from asset's `original_filename` field

**Responsibilities:**
- Validate asset access via authorization policy
- Ensure asset is not archived (Phase 2.8: future-proof placeholder)
- Generate signed S3 URL (15 minutes expiration)
- Redirect to S3
- Track download metric
- Emit placeholder hooks for analytics (logged)

**Phase 2.8 Compatibility:**
- Placeholder check for archived assets (not yet implemented)
- When Phase 2.8 is implemented, archived assets will return 403 error

**Signed URL Strategy:**
- URLs expire after 15 minutes
- Generated using `Storage::disk('s3')->temporaryUrl()`
- Direct redirect to S3 (no proxying)

---

### Access Modes

**DownloadAccessMode Enum:**
- `PUBLIC`: Anyone with the link can access the download
- `TEAM`: Only authenticated team members of the tenant can access
- `RESTRICTED`: Only specific users can access (future implementation)

**Implementation:**
- `PUBLIC`: No authentication required
- `TEAM`: Requires authenticated user who belongs to download's tenant
- `RESTRICTED`: Currently stubbed as team access (future granular permission control needed)

**Security:**
- All access checks happen before signed URL generation
- Tenant scope validation ensures downloads can't be accessed across tenants
- Signed URLs are short-lived (10 minutes for ZIPs, 15 minutes for single files)

---

### Security & Safety

**Signed URL Expiration:**
- ZIP downloads: 10 minutes
- Single file downloads: 15 minutes
- Prevents long-lived URLs from being shared indefinitely

**No Direct S3 Paths:**
- ZIP paths (`zip_path`) are never exposed directly in responses
- All downloads use signed URLs that expire
- S3 bucket and key structure remains internal

**No ZIP Serving via PHP Streams:**
- All ZIP downloads redirect to S3 signed URLs
- No proxying through application (prevents memory issues)
- Direct S3-to-client transfer for better performance

**Access Validation:**
- Tenant scope checks prevent cross-tenant access
- Authorization policies ensure user has permission
- Download status and ZIP status validated before URL generation

---

### Error Handling

**Normalized Error Patterns:**
- Consistent JSON error responses with `message` field
- Appropriate HTTP status codes (404, 403, 410, 422, 500)
- User-friendly error messages

**Fail-Safe Scenarios:**
- ZIP missing: Returns 404
- ZIP building: Returns 422 with message "ZIP file is being built"
- Access denied: Returns 403
- Expired download: Returns 410
- Invalid status: Returns 422 with specific error message

**Logging:**
- All errors logged with context (download_id, user_id, error message)
- Download access logged for analytics (placeholder)
- Failed URL generation logged with full error details

---

### Explicit Non-Goals

Phase 3.1 Step 4 explicitly does **NOT** include:

- ❌ Hosted press-kit pages (public URLs)
- ❌ Analytics aggregation logic (only logging placeholders)
- ❌ `last_accessed_at` field updates (commented TODO)
- ❌ Real-time download status updates
- ❌ Download progress tracking
- ❌ Download retry logic
- ❌ Batch download endpoints

These are reserved for future steps/phases.

---

---

## Step 5: Downloader Analytics Hooks & Lifecycle Events

### Event Definitions

**Location:** `app/Enums/EventType.php`

**Download Group Events:**
- `download_group.created` - Download group created
- `download_group.ready` - Download group is ready for use
- `download_group.invalidated` - Download group invalidated (asset list changed for living downloads)
- `download_group.failed` - Download group creation/processing failed

**Download ZIP Events:**
- `download.zip.requested` - ZIP download requested (signed URL generated)
- `download.zip.completed` - ZIP download completed (best-effort, emitted when URL generated)
- `download.zip.failed` - ZIP build failed

**Asset Download Events:**
- `asset.download.created` - Single asset download requested (reuses existing event)
- `asset.download.completed` - Single asset download completed (reuses existing event)

**ZIP Build Events:**
- `zip.generated` - ZIP file built successfully (reuses existing event)

---

### Event Payload Structure

All download events include consistent, structured payloads designed for AI agents and analytics:

**Standard Fields:**
- `tenant_id` - Tenant ID (always present)
- `user_id` - User ID (nullable, system events have null)
- `download_id` - Download ID (if applicable)
- `asset_id` - Asset ID (if applicable, for single asset downloads)
- `download_type` - `'snapshot'` or `'living'`
- `source` - `'grid'`, `'drawer'`, `'collection'`, `'public'`, `'admin'`
- `file_type` - Asset file type (for single asset downloads)
- `size_bytes` - File/ZIP size in bytes (if known)
- `context` - `'zip'` or `'single'` (download context)

**Additional Fields:**
- `access_mode` - `'public'`, `'team'`, `'restricted'`
- `version` - Download version (increments on invalidation)
- `zip_path` - S3 path to ZIP file (for ZIP events)
- `zip_size_bytes` - ZIP file size in bytes
- `error` - Error message (for failure events)
- `reason` - Invalidation reason (for invalidation events)

---

### Event Emission Points

**Download Group Creation:**
- **Location:** Future download creation endpoint (not yet implemented)
- **Event:** `download_group.created`
- **Actor:** User who created the download
- **When:** After download group record is created in database

**Download Group Ready:**
- **Location:** Future download creation logic (when assets are attached)
- **Event:** `download_group.ready`
- **Actor:** System
- **When:** When download status transitions to `READY`

**ZIP Build Success:**
- **Location:** `BuildDownloadZipJob::handle()`
- **Event:** `zip.generated`
- **Actor:** System
- **When:** After ZIP is successfully uploaded to S3 and download model updated
- **Metadata:** Includes `zip_size_bytes` and `zip_path`

**ZIP Build Failure:**
- **Location:** `BuildDownloadZipJob::handle()` (catch block)
- **Event:** `download.zip.failed`
- **Actor:** System
- **When:** When ZIP build fails and download status set to `FAILED`
- **Metadata:** Includes error message

**ZIP Download Requested:**
- **Location:** `DownloadController::download()`
- **Event:** `download.zip.requested`
- **Actor:** User requesting download
- **When:** After access validation and signed URL generation
- **Also emits:** `download.zip.completed` (best-effort, when URL is generated)

**Download Group Invalidated:**
- **Location:** `Download::invalidateZipIfNeeded()`
- **Event:** `download_group.invalidated`
- **Actor:** System
- **When:** When living download's asset list changes and ZIP is invalidated
- **Metadata:** Includes `version` and `reason: 'asset_list_changed'`

**Single Asset Download Requested:**
- **Location:** `AssetController::download()`
- **Event:** `asset.download.created`
- **Actor:** User requesting download
- **When:** After access validation and signed URL generation
- **Metadata:** Includes `context: 'single'`, `file_type`, `size_bytes`

---

### Asset Download Count Logic (Design-Level)

**Note:** This is design documentation only. Aggregation is not implemented in Phase 3.1 Step 5.

**ZIP Download Counting:**
- Each ZIP download increments:
  - Download group's download count (future field)
  - Each included asset's download count
- For living downloads:
  - Counts apply only to assets present at the time of download
  - Historical counts preserved via `version` field
  - If assets are added/removed after download, counts do not retroactively change

**Single Asset Download Counting:**
- Each single asset download increments:
  - Asset's download count
  - No download group created (drawer/download button clicks)

**Counting Rules:**
- Downloads are counted when signed URL is generated (request time)
- Actual file transfer completion is not tracked (best-effort completion events)
- Duplicate downloads from same user within short time window may be deduplicated (future implementation)
- Public downloads without authentication are counted (user_id = null)

**Living Downloads Impact:**
- Download counts change over time as assets are added/removed
- Historical download counts can be reconstructed using:
  - `download_zip_requested` events
  - `download_asset` pivot table snapshots at download time
  - `version` field to track asset list changes

**Analytics Aggregation (Future Phase):**
- Per-asset download counts: Aggregate `asset.download.created` and ZIP downloads per asset
- Per-download-group counts: Aggregate `download.zip.requested` events
- Time-series trends: Group by date, download_type, source
- User behavior: Group by user_id, download patterns

---

### Event Emission Service

**Location:** `app/Services/DownloadEventEmitter.php`

**Purpose:** Centralized service for emitting download-related events with consistent payloads.

**Methods:**
- `emitDownloadGroupCreated(Download $download)` - Emit download group created event
- `emitDownloadGroupReady(Download $download)` - Emit download group ready event
- `emitDownloadZipRequested(Download $download)` - Emit ZIP download requested event
- `emitDownloadZipCompleted(Download $download)` - Emit ZIP download completed (best-effort)
- `emitDownloadZipBuildSuccess(Download $download, int $zipSizeBytes)` - Emit ZIP build success
- `emitDownloadZipFailed(Download $download, ?string $error)` - Emit ZIP build failure
- `emitDownloadGroupInvalidated(Download $download)` - Emit download group invalidated event
- `emitAssetDownloadRequested(Asset $asset)` - Emit single asset download requested event

**Safety:**
- All methods wrapped in try-catch to prevent event emission failures from breaking downloads
- Failures are logged as warnings/debug messages
- Returns void (does not throw exceptions)

---

### Logging Strategy

**Structured Logging:**
- All events are logged using `Log::info()` with structured JSON payloads
- Event metadata includes all standard fields for analytics
- No PII leakage (user emails, names not included in logs)

**Activity Events:**
- Events are also stored in `activity_events` table via `ActivityRecorder` service
- Enables queryable event history for analytics
- Immutable, append-only records

**AI-Ready Signals:**
- Event payloads are structured for machine consumption
- Consistent field names and types
- Context fields enable grouping and filtering
- Error fields enable failure pattern analysis

---

### Explicit Non-Goals

Phase 3.1 Step 5 explicitly does **NOT** include:

- ❌ Analytics dashboards or reports
- ❌ Real-time metric aggregation
- ❌ Download count aggregation logic
- ❌ Download completion tracking (S3 access logs analysis)
- ❌ Duplicate download deduplication
- ❌ Download trend analysis UI
- ❌ Export/CSV generation for analytics

These are reserved for future phases.

---

---

## Step 6: Minimal Asset Drawer Download Action (Test-Only)

### Overview

This step provides a **thin UI wire-up** for testing the existing single-asset download endpoint. This is **NOT a feature** — it is a wiring step to enable manual testing of download delivery.

### Implementation

**Location:** `resources/js/Components/AssetDrawer.jsx`

**Button:**
- Label: "Download"
- Placement: After analytics/metrics section, before File Information
- Behavior: Direct link to `GET /app/assets/{asset}/download`
- Styling: Primary button (indigo)

**Visibility:**
- Shows only if asset has an `id`
- No additional permission checks (existing endpoint handles authorization)
- No plan gating, archive state, or publish state checks (deferred to later phases)

**User Experience:**
- Clicking button opens download in new tab (`target="_blank"`)
- Backend forces download via `ResponseContentDisposition: attachment` header
- Browser downloads file instead of previewing inline
- No modal, loading state, or error UI (relies on existing global error handling)

### Guard Comments

The implementation includes guard comments indicating:
- This is a temporary test-only UI
- Do not expand into full download UX in this location

### Explicit Non-Goals

This step explicitly does **NOT** include:
- ❌ Download baskets or selectors
- ❌ Download group UI
- ❌ Progress indicators or polling
- ❌ Plan gating UI
- ❌ Analytics aggregation UI
- ❌ Refactoring of existing drawer layout

Full download UI features are deferred to a later phase.

---

**Last Updated:** 2024  
**Current Step:** Step 6 (Minimal Asset Drawer Download Action - Test-Only)  
**Next Steps:** Full download UI, hosted press-kit pages, analytics aggregation
