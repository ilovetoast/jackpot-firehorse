# FFmpeg-native Studio composition export

## Current pipeline (before this doc)

1. **Legacy bitmap** (`render_mode=legacy_bitmap`): downloads the primary video asset, FFmpeg scales/pads to the canvas, then composites **image / generative_image** layers with `z` above the primary video. Text, masks, blend modes, and gradient fills are not represented in the MP4.
2. **Canvas runtime** (`render_mode=canvas_runtime`): builds a signed internal URL to `CompositionExportRender`, runs **Node + Playwright** to capture a PNG sequence, then **FFmpeg** merges frames over the trimmed base video and publishes the MP4.

## Target pipeline

- **FFmpeg native** (`render_mode=ffmpeg_native`): normalizes the saved composition document into an internal render model, **stages all media locally**, rasterizes text to transparent PNGs where needed, builds a deterministic **`filter_complex`**, runs **FFmpeg only** (no browser), then uses the same **MP4 publisher** as the other paths.
- **Browser canvas** remains available when `STUDIO_RENDERING_DRIVER=browser_canvas` and `STUDIO_VIDEO_CANVAS_RUNTIME_EXPORT_ENABLED=true`, or when an unsupported composition opts into **browser fallback** (see `config/studio_rendering.php`).

## Renderer abstraction

PHP contract: `App\Studio\Rendering\Contracts\CompositionRenderer` with `render(CompositionRenderRequest): CompositionRenderResult`.

- **FfmpegNativeCompositionRenderer** — FFmpeg graph + subprocess (default driver).
- **BrowserCanvasCompositionRenderer** — delegates to `StudioCompositionCanvasRuntimeVideoExportService` (Playwright path).

The queued job still resolves the tenant/user and calls `StudioCompositionVideoExportOrchestrator`, which selects legacy bitmap vs native/browser full-scene export.

## Migration / rollout

1. Deploy with `STUDIO_RENDERING_DRIVER=ffmpeg_native` (default in config).
2. Ensure workers have **FFmpeg/ffprobe**.
3. **Fonts (text layers):** tenant fonts are copied from Laravel storage (e.g. S3) into **`storage/app/{STUDIO_RENDERING_FONT_CACHE_DIR}`** (default `studio/font-cache`) as **TTF/OTF** only. Imagick or GD+FreeType renders text using **absolute local paths** — never signed URLs or HTTP fetches for font binaries.
4. Set **`STUDIO_RENDERING_DEFAULT_FONT_PATH`** when any visible text layer relies on CSS **font-family** only (no `font_asset_id` / explicit local path). If every text layer references a tenant font asset, the default path is optional at request time, but a default remains useful as a safety net for partial payloads.
5. Keep Playwright optional unless you rely on browser fallback or `browser_canvas` driver.
6. Optional: set `QUEUE_VIDEO_HEAVY_STUDIO_CANVAS_QUEUE` only for browser jobs; FFmpeg-native jobs use the standard heavy video queue.

### Tenant / brand fonts (V1)

- **Supported formats:** `.ttf`, `.otf` (configurable via `STUDIO_RENDERING_ALLOWED_FONT_EXTENSIONS`). **Not supported:** `.woff`, `.woff2`, `.eot`, SVG fonts (export fails with a clear code).
- **Payload keys** (editor may send any subset): `font_asset_id`, `fontAssetId`, nested `font.asset_id` / `font.assetId`, optional `font.disk` + `font.storage_path` for **local/public disks only**; remote disks require `font_asset_id` so bytes are read via `EditorAssetOriginalBytesLoader`.
- **Tenant/brand checks:** font `Asset` rows must match the composition tenant; if the asset has a non-null `brand_id`, it must match the composition’s `brand_id`.
- **Explicit failure:** if the user selected a tenant font (`font_asset_id`, explicit path, or disk+path) and resolution fails, the job **does not** silently fall back to the default font.
- **Cache invalidation:** cached filenames include a hash of asset id, storage key, version id, version `updated_at`, version size, and asset `updated_at` so replaced files are re-staged.

### Troubleshooting fonts

| Symptom | Things to check |
|--------|------------------|
| `missing_default_font_path` | At least one text layer has no explicit font; set `STUDIO_RENDERING_DEFAULT_FONT_PATH`. |
| `font_asset_not_found` | UUID wrong or asset belongs to another tenant. |
| `font_asset_wrong_brand` | Font asset `brand_id` ≠ composition `brand_id`. |
| `unsupported_font_extension` | Re-upload as TTF/OTF or convert server-side (not built-in). |
| `font_cache_read_failed` / `font_asset_no_storage_path` | `storage_root_path` / version `file_path` missing or object missing in bucket. |
| `rasterizer_missing_imagick_and_gd` | Install **Imagick** or **PHP GD** with FreeType on workers. |
| `font_remote_disk_requires_asset_id` | Do not point `font.disk` at `s3` without an asset id — use DAM font assets. |

Server packages: **PHP Imagick** (recommended) or **GD + FreeType**, plus **ffmpeg** (includes **ffprobe** on Ubuntu/Debian), **fontconfig**, and common image libs — see the install snippets in [PRODUCTION_WORKER_SOFTWARE.md](../environments/PRODUCTION_WORKER_SOFTWARE.md#studio-ffmpeg-native-export-workers).

## Unsupported in FFmpeg native V1

- **Mask** layers (fail or browser fallback).
- **Non-normal** `blendMode` on raster/video (fail or browser fallback).
- **Gradient** fills are **not** drawn as true gradients in V1; pad/letterbox color uses the same **solid approximation** as legacy FFmpeg (`gradientEndColor` → `gradientStartColor` → `color` via {@see \App\Services\Studio\StudioCompositionVideoExportMediaHelper::resolvePadColorForFfmpeg}). A notice is logged when a visible gradient fill is present.
- **Multiple stacked video layers** as overlays: only the **primary** video is fully supported as the base track; extra video layers above the base may be rejected in V1 depending on document complexity.

See `StudioCompositionFfmpegNativeFeaturePolicy` for the authoritative gate used at export request time.
