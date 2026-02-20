# Asset Grid Category Wipe Fix — February 2026

## Summary

Assets were disappearing from the asset grid after refresh. The root cause was **FinalizeAssetJob replacing asset metadata with version metadata**, which wiped `category_id`, `metadata_extracted`, and other upload-time fields. The asset grid filters by `metadata->category_id`, so assets with null `category_id` were excluded.

## Root Cause

In `FinalizeAssetJob`, when the version pipeline completed:

```php
$metadata = $currentVersion->metadata ?? [];
$metadata['pipeline_completed_at'] = now()->toIso8601String();
$asset->update(['metadata' => $metadata, ...]);
```

The **version** metadata only contains version-scoped fields (thumbnails, dimensions, etc.). It does **not** contain:
- `category_id` (set at upload by UploadCompletionService)
- `metadata_extracted` (set by ExtractMetadataJob on asset)
- `preview_generated` (set by GeneratePreviewJob on asset)

Replacing asset metadata with version metadata wiped these fields. Assets then failed the grid filter:
`WHERE CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.category_id")) AS UNSIGNED) IN (viewableCategoryIds)`.

## Fix

**FinalizeAssetJob** now **merges** asset metadata with version metadata instead of replacing:

```php
$versionMetadata = $currentVersion->metadata ?? [];
$assetMetadata = $asset->metadata ?? [];
$metadata = array_merge($assetMetadata, $versionMetadata);
$metadata['pipeline_completed_at'] = now()->toIso8601String();
```

Version metadata overlays asset metadata for overlapping keys (e.g. thumbnails). Asset-scoped fields (`category_id`, etc.) are preserved.

## Recovery for Affected Assets

Assets that were already affected have `metadata->category_id` = null. To recover:

```bash
# List affected assets (no changes)
php artisan assets:recover-category-id

# Assign a category to all affected assets
php artisan assets:recover-category-id --category=5

# Limit to a specific tenant/brand
php artisan assets:recover-category-id --category=5 --tenant=1 --brand=1

# Dry run to preview
php artisan assets:recover-category-id --category=5 --dry-run
```

Use the category ID that matches your "Logos" or primary asset category for the brand.

## Visibility Consistency (Hermetically Sealed)

The Admin modal's "Visible in grid: Yes/No" must match actual grid display. Previously, `Asset::isVisibleInGrid()` and `AssetVisibilityService` only checked lifecycle (status, published_at, archived_at) but not `category_id`. The grid also filters by `metadata->category_id`, so assets with null category_id showed "Visible: Yes" but did not appear in the grid.

**Fix**: `isVisibleInGrid()` and `scopeVisibleInGrid()` now require `metadata.category_id` to be set. `AssetVisibilityService` returns "Category not set" with recovery instructions when category_id is missing.

## Orange Dot (Uploading Status)

The orange dot in the top-right of asset thumbnails indicates the asset is still processing (e.g. `thumbnail_status` pending, or `analysis_status` not complete). This is expected during upload and does not cause removal from the grid.
