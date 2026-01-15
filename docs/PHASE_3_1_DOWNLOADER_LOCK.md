# Phase 3.1 — Downloader System (LOCKED)

**Status:** ✅ COMPLETE & LOCKED  
**Date Locked:** 2024

---

## Overview

Phase 3.1 introduced the complete downloader system foundation, including download group models, lifecycle management, ZIP generation and cleanup, S3-based delivery, analytics hooks, and minimal test-only UI wiring.

**This phase is IMMUTABLE.** All future phases must consume Phase 3.1 outputs without modifying existing behavior.

---

## Locked Components

### Models & Schema

**`app/Models/Download.php`**
- Download group model with lifecycle methods
- Snapshot vs. living download logic
- ZIP invalidation helpers
- Expiration and hard delete logic
- **DO NOT** refactor model structure or lifecycle methods

**Database Schema:**
- `downloads` table structure
- `download_asset` pivot table
- All indexes and constraints
- **DO NOT** modify schema without backward compatibility

**Enums:**
- `DownloadStatus`
- `DownloadType`
- `ZipStatus`
- `DownloadSource`
- `DownloadAccessMode`
- **DO NOT** modify enum values or semantics

### Jobs

**`app/Jobs/BuildDownloadZipJob.php`**
- ZIP file generation from assets
- S3 upload logic
- Status transitions
- Error handling
- **DO NOT** refactor ZIP build flow or S3 interaction patterns

**`app/Jobs/CleanupExpiredDownloadsJob.php`**
- Hard delete detection
- S3 ZIP cleanup
- Database cleanup
- Batch processing logic
- **DO NOT** modify cleanup rules or deletion patterns

### Controllers

**`app/Http/Controllers/DownloadController.php`**
- ZIP download endpoint
- Access validation
- Signed URL generation
- Status checks
- **DO NOT** modify delivery behavior or access rules

**`app/Http/Controllers/AssetController.php::download()`**
- Single asset download endpoint
- Force download via Content-Disposition
- Signed URL generation with ResponseContentDisposition
- **DO NOT** modify download forcing behavior or URL generation

### Services

**`app/Services/DownloadExpirationPolicy.php`**
- Expiration calculation rules (design stubs)
- Grace window logic (design stubs)
- Plan-aware expiration (design stubs)
- **DO NOT** modify policy contract (implementation may be added in future phase)

**`app/Services/DownloadEventEmitter.php`**
- Event payload structure
- Emission points
- Event types used
- **DO NOT** modify event payload structure or emission logic

### Routes

**Routes (in `routes/web.php`):**
- `GET /downloads/{download}/download` - ZIP download
- `GET /assets/{asset}/download` - Single asset download
- **DO NOT** modify route definitions or behavior

### UI Components

**`resources/js/Components/AssetDrawer.jsx`**
- Minimal download button (test-only)
- Direct link to download endpoint
- New tab behavior with `target="_blank"`
- **DO NOT** expand into full download UX in this component

---

## Explicit Non-Goals (Remain Out of Scope)

Phase 3.1 explicitly does **NOT** include:

- ❌ Download baskets or selectors
- ❌ Download group UI or management interface
- ❌ Progress indicators or polling
- ❌ Hosted press-kit pages
- ❌ Plan gating UI
- ❌ Permission UI for downloads
- ❌ Analytics aggregation or dashboards
- ❌ Download history or listings
- ❌ Download retry logic (beyond job retries)
- ❌ Asset archive & publish state enforcement (Phase 2.8)

These remain explicitly out of scope for Phase 3.1.

---

## Allowed Future Extensions

### New Phases May:

**Consume Downloader Events:**
- Read `activity_events` table for download analytics
- Aggregate download metrics for dashboards
- Use events for AI analysis or pattern detection
- Build reports based on event payloads

**Implement Related Systems:**
- Phase 2.8: Asset archive & publish state enforcement
- Future phase: Hosted press-kit pages (using download groups)
- Future phase: Download baskets/selectors (using existing endpoints)
- Future phase: Analytics dashboards (using event data)

**Add UI Layers:**
- Build UI that calls existing download endpoints
- Add download management interfaces (without changing endpoints)
- Create download history views (read-only)

**Configuration Changes:**
- Adjust signed URL TTLs (config-only, no contract changes)
- Modify job batch sizes or retry counts (within existing structure)

### New Phases Must NOT:

**Modify Locked Components:**
- Refactor download models or lifecycle methods
- Change ZIP generation or cleanup logic
- Alter delivery behavior or signed URL strategy
- Modify analytics event payloads or emission points
- Change access mode semantics or validation rules

**Break Contracts:**
- Download event payload structure
- ZIP file naming convention
- S3 path structure
- Access mode behavior
- Status transition rules

---

## Lock Enforcement Rules

### Authorization Principle

**Phase 3.1 contracts are AUTHORITATIVE.**

All future phases must ADAPT to Phase 3.1, not modify it. If behavior appears incorrect:
1. Fix via new layers (wrappers, middleware, UI)
2. Do NOT refactor Phase 3.1 code directly
3. Ensure backward compatibility if changes are required

### Additive Changes Only

Any required changes must be:
- **Additive:** Add new features/endpoints, don't modify existing ones
- **Backward-compatible:** Don't break existing consumers
- **Documented:** Clearly separate new behavior from Phase 3.1

### Guard Comments

All Phase 3.1 files include guard comments indicating they are locked. When adding new code:
- Do NOT remove guard comments
- Do NOT refactor guarded code
- Do NOT modify behavior of guarded components

---

## Downloader Contracts

### Download Event Payload Structure

All download events include consistent fields:
- `tenant_id`, `user_id`, `download_id`, `asset_id`
- `download_type`, `source`, `file_type`, `size_bytes`
- `context` (`'zip'` or `'single'`)
- Additional fields: `access_mode`, `version`, `zip_path`, etc.

**This structure is immutable.** Future phases must consume events as-is.

### Signed URL Strategy

- ZIP downloads: 10 minutes expiration
- Single asset downloads: 15 minutes expiration
- Force download via `ResponseContentDisposition: attachment`
- Direct S3 redirect (no proxying)

**This strategy is immutable.** TTLs may be adjusted via config, but behavior contract remains.

### Access Modes

- `PUBLIC`: Anyone with link can access
- `TEAM`: Authenticated users who are members of tenant
- `RESTRICTED`: Currently stubbed as team access (future implementation)

**Access mode semantics are immutable.** Future implementation may extend RESTRICTED mode, but existing modes must not change.

### ZIP File Naming

- S3 Path: `downloads/{download_id}/download.zip`
- Duplicate filenames in ZIP: Prefixed with index (`file_1.ext`, `file_2.ext`)

**ZIP structure is immutable.** Future phases must respect this convention.

---

## Future Phase Integration Guidelines

### Consuming Download Events

```php
// ✅ CORRECT: Read events without modifying emission
$events = ActivityEvent::where('event_type', EventType::DOWNLOAD_ZIP_REQUESTED)
    ->where('tenant_id', $tenant->id)
    ->get();

// ❌ INCORRECT: Modify event emission logic
DownloadEventEmitter::emitDownloadZipRequested($download); // Don't modify this
```

### Building on Downloader Foundation

```php
// ✅ CORRECT: Add new endpoints that use existing models
Route::get('/downloads/{download}/stats', function (Download $download) {
    // Read-only statistics using Phase 3.1 models
});

// ❌ INCORRECT: Modify existing download endpoint behavior
// Don't change DownloadController::download() logic
```

### Extending Access Controls

```php
// ✅ CORRECT: Add middleware or wrappers
// Add new access checks without modifying existing validation

// ❌ INCORRECT: Modify access validation in DownloadController
// Don't change validateAccess() method
```

---

## Migration & Backward Compatibility

If schema changes are required in future phases:
- Must be additive only (new columns, new tables)
- Must not break existing Phase 3.1 queries
- Must maintain existing field semantics
- Must include migration rollback plans

If behavior changes are required:
- Must be opt-in or feature-flagged
- Must maintain Phase 3.1 default behavior
- Must be clearly documented as new phase additions

---

## Related Documentation

- `docs/PHASE_3_1_DOWNLOADER_FOUNDATIONS.md` - Complete Phase 3.1 implementation details
- `docs/PHASE_2_5_OBSERVABILITY_LOCK.md` - Phase 2.5 (upload observability) lock
- Phase 2.8 Design Notice - Asset lifecycle rules for future implementation

---

**Locked By:** Phase 3.1 Implementation  
**Lock Date:** 2024  
**Next Phase:** To be determined (may include hosted press-kit pages, analytics aggregation, or download UI expansion)
