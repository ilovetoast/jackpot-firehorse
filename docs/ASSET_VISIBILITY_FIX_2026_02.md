# Asset Visibility Fix — February 2026

## Executive Summary

Assets were disappearing from the asset grid after upload when processing jobs failed or encountered errors. This document describes the root cause, the fix, and per-file-type behavior for the six test files.

## Root Cause: Why Assets Disappeared

### Primary Cause: `processing_failed` in Metadata

When any pipeline job failed (thumbnail generation, metadata extraction, AI tagging, etc.), `AssetProcessingFailureService::recordFailure()` was called with `preserveVisibility=true` (correctly, so status stayed VISIBLE). **However**, it always set `metadata['processing_failed'] = true` regardless of `preserveVisibility`.

The Asset model's visibility logic (`isVisibleInGrid()`, `scopeVisibleInGrid()`) **excluded** assets where `processing_failed` was true. So even though:
- `status` remained VISIBLE
- `published_at` was set
- The asset was not archived or deleted

…the asset was hidden from the grid because of `processing_failed`.

### Secondary Cause: LifecycleResolver vs. Asset Model

The main asset grid uses `LifecycleResolver` for filtering, which does **not** check `processing_failed`. However:
- `scopeVisibleInGrid()` is used by Admin Operations and other features
- `AssetVisibilityService` uses `isVisibleInGrid()` for diagnostics
- Inconsistent visibility logic caused confusion and potential filtering bugs

## The Fix

### 1. Remove `processing_failed` from Visibility Logic

**Asset model** (`app/Models/Asset.php`):
- `isVisibleInGrid()`: Removed the `processing_failed` check. Assets are now hidden **only** when: deleted, archived, unpublished, or status=FAILED/HIDDEN.
- `scopeVisibleInGrid()` / `scopeNotVisibleInGrid()`: Removed the `processing_failed` SQL condition.

**Contract**: Processing failures must **never** hide assets. Users must always see their uploads so they can retry, download, or fix.

### 2. AssetProcessingFailureService: Conditional `processing_failed`

When `preserveVisibility=true` (used by all pipeline jobs), we no longer set `metadata['processing_failed']`. We still record `failure_reason`, `failed_job`, `failure_attempts`, etc. for observability. Only when `preserveVisibility=false` (catastrophic failures) do we set `processing_failed`.

### 3. AssetVisibilityService

Updated to only check `status === FAILED` for the "retry pipeline" recommendation. Removed the `processing_failed` metadata check since it no longer affects visibility.

### 4. Unsupported File Types (ZIP, etc.)

Added explicit early-exit checks so unsupported types never attempt:
- **ComputedMetadataService**: Skips dimensions/orientation/color_space for ZIP, archives, etc.
- **PopulateAutomaticMetadataJob**: Skips color analysis and dominant colors for unsupported types; advances `analysis_status` to `scoring` so the pipeline completes.

ZIP files already get `thumbnail_status=SKIPPED` and `thumbnail_skip_reason=unsupported_format:zip` from `GenerateThumbnailsJob`. The pipeline now skips downstream jobs explicitly, so we "know not to try again."

---

## Per-File-Type Behavior (Six Test Files)

Based on the upload modal and grid screenshots, the six files are:

| File | Type | Expected Behavior | Errors That Caused Removal | Fix |
|------|------|-------------------|---------------------------|-----|
| **Velvet-Hammer-download-2026-02-20 (1).zip** | ZIP | No preview, no dimensions, no color analysis. File-type icon only. | None — ZIP was likely the only one visible because it had no processing failures. Other files failed and got `processing_failed` set. | Visibility fix ensures all stay visible. ZIP gets explicit skip in ComputedMetadataJob and PopulateAutomaticMetadataJob. |
| **behive-details-0025 (1).jpg** | JPEG | Full preview, dimensions, color analysis, AI tagging. | Likely: ExtractMetadataJob, ComputedMetadataJob, or PopulateAutomaticMetadataJob failed (e.g. large file, timeout). `processing_failed` hid it. | Visibility fix. |
| **vg-email-v2 (2).ai** | Adobe Illustrator | AI/EPS thumbnail via Imagick. Dimensions, color. | Likely: Imagick/AI handler failed or timed out. `processing_failed` hid it. | Visibility fix. |
| **ocetds1804686.tif** | TIFF | TIFF thumbnail (Imagick). Dimensions, color. | Likely: Imagick missing or TIFF-specific error. `processing_failed` hid it. | Visibility fix. |
| **vhb-icon.ico** | ICO | Unsupported for thumbnails (config: `unsupported` → `ico`). File-type icon. | Likely: Thumbnail job tried, failed or skipped. If failed path ran, `processing_failed` hid it. | Visibility fix. ICO is in `file_types.unsupported`; should get `thumbnail_status=SKIPPED`. |
| **bg-17.png** | PNG | Full preview, dimensions, color. | Likely: Similar to JPG — any job failure set `processing_failed`. | Visibility fix. |

### Metadata Extraction by File Type

| Type | Thumbnail | Dimensions | Color Analysis | AI Tagging |
|------|-----------|------------|----------------|------------|
| ZIP | No (skip) | No | No | No |
| JPG/PNG | Yes | Yes | Yes | Yes |
| AI/EPS | Yes (Imagick) | Yes | Yes | Yes |
| TIFF | Yes (Imagick) | Yes | Yes | Yes |
| ICO | No (skip) | No | No | No |

### What Was Extracted (When Jobs Succeeded)

- **ZIP**: `original_filename`, `size_bytes`, `mime_type`, `extracted_by`. No dimensions, no color.
- **JPG/PNG**: Full EXIF, dimensions, orientation, color_space, resolution_class, dominant_colors.
- **AI/TIFF**: Same as images when Imagick succeeds.
- **ICO**: Basic metadata only; thumbnails skipped.

---

## Visibility Contract (Canonical)

An asset is **visible** in the default grid when **all** of:
- `deleted_at` IS NULL
- `archived_at` IS NULL
- `published_at` IS NOT NULL
- `status` IN (VISIBLE, FAILED) — FAILED assets remain visible so users can retry

An asset is **hidden** only when:
- Deleted
- Archived
- Unpublished (pending approval)
- status = HIDDEN (e.g. pending approval)

**Processing failures (`processing_failed`, `thumbnail_status=FAILED`, etc.) must never hide assets.**
