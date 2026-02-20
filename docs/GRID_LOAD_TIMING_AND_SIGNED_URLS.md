# Grid Load Timing & Signed URL Generation

Diagnostic guide for Assets/Deliverables grid load performance in staging.

## Controller Timing Logs

### AssetController::index
- `[ASSET_GRID_TIMING] AssetController::index` — after asset transform
  - `total_ms` — from method start
  - `after_query_ms` — DB query + pagination
  - `after_transform_ms` — mapping assets (includes thumbnail URL generation)
  - `assets_count` — number of assets returned
  - `s3_presign_count` — assets with `video_preview_url` (triggers S3 presign per video)
- `[ASSET_GRID_TIMING] AssetController::index before Inertia` — before Inertia::render
  - `total_ms` — full controller time including filterableSchema, availableValues
  - `before_return_ms` — time spent on filterableSchema + availableValues

### DeliverableController::index
- `[DELIVERABLE_GRID_TIMING] DeliverableController::index` — same structure
- `[DELIVERABLE_GRID_TIMING] DeliverableController::index before Inertia` — before return

## Frontend Timing

- `[ASSET_GRID_TIMING] navigation to first grid render` — console.info when Assets Index receives assets
- `[DELIVERABLE_GRID_TIMING] navigation to first grid render` — same for Deliverables

`window.__inertiaVisitStart` is set on `router.on('start')` in app.jsx.

## Where Signed URLs Are Generated

### Asset model accessors (on attribute access)
- **`video_preview_url`** — `Asset.php` accessor calls `TenantBucketService::getPresignedGetUrl` when asset has `metadata['video_preview']` and is video
- **`video_poster_url`** — same pattern for poster image

These run during asset mapping when `$asset->video_preview_url` is accessed in the transform. **AssetController index** includes `video_preview_url` in the payload; **DeliverableController** does not.

### Thumbnail URLs (route-based, not presigned in controller)
- **AssetController / DeliverableController** — use `route('assets.thumbnail.preview', ...)` and `route('assets.thumbnail.final', ...)` — these are **route URLs**, not S3 signed URLs
- **AssetThumbnailController** — when the browser requests the thumbnail route, the controller generates a signed URL and redirects. Signing happens **per image request**, not during index.

### Storage service
- **TenantBucketService::getPresignedGetUrl** — single place that generates S3 presigned URLs
- Called by: Asset accessors (video_preview_url, video_poster_url), DownloadController, PublicCollectionController, AssetController::previewUrl

### Summary
| Location | When | Count |
|----------|------|-------|
| Asset::video_preview_url | During index asset mapping (Assets only) | 1 per video asset |
| Asset::video_poster_url | If accessed | 1 per video asset |
| AssetThumbnailController | On each thumbnail image request | 1 per image load |
| DownloadController | On download action | 1 per download |
| PublicCollectionController | On public collection asset download | 1 per download |

## Interpreting Timing

- **after_query_ms** high → DB query or pagination slow
- **after_transform_ms** high → metadata hydration, category lookup, or S3 presigning (video assets)
- **before_return_ms** high → filterableSchema resolution or availableValues query
- **Frontend ms** high but backend low → network, Inertia serialization, or React render
- **Frontend ms** high and backend high → backend is bottleneck
