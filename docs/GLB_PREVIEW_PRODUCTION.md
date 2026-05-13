# GLB realtime preview — production notes (Phase 5D)

## Scope

- **Realtime in-browser preview** is **GLB-only** via `<model-viewer>` when the registry type is `model_glb`, `DAM_3D` is enabled, and signed poster/viewer CDN URLs resolve.
- **OBJ, FBX, BLEND, and glTF with external resources** are **not** served as interactive realtime previews in the product UI today. Those formats may still appear in the library with **raster poster thumbnails** (or placeholders) where the pipeline supports them; full interactive viewing requires canonical GLB delivery (future conversion pipeline — not part of Phase 5D).

## Configuration

- **`DAM_3D`**: `config/dam_3d.php` / `DAM_3D_ENABLED` gates registry 3D thumbnail generation and the GLB `<model-viewer>` path. When disabled, GLB assets do not get interactive viewer URLs even if metadata contains a native viewer key.
- **Posters**: `metadata.preview_3d.poster_path` holds the **raster** poster object key; the API exposes `preview_3d_poster_url` and `preview_3d_revision` (opaque) for cache busting — never raw S3 keys in tenant JSON.

## Browsers

- Use a **current Chromium, Firefox, or Safari** with **WebGL** enabled. `<model-viewer>` relies on standard web platform features; unsupported or locked-down environments fall back to the **poster / thumbnail** path.

## CDN, signed URLs, and CORS

- Delivery runs through **`AssetDeliveryService`** and CloudFront (non-local). Viewer and poster URLs must be **reachable by the browser** from the app origin (typical same-site CDN host).
- **CORS** on the bucket / distribution must allow **GET** for model and image fetches the viewer issues. If signing fails, structured logs use `preview_3d.signed_url_resolution_failed` or `preview_3d.signed_url_empty` (see `Preview3dDeliveryUrls`).

## Operational limits

- Prefer **GLB files under ~50 MB** for a smooth UX; very large models may fail on low-memory clients and will **fall back** to poster mode after a viewer error.
- Heavy scenes may increase GPU memory; treat large assets like large images for support expectations.

## Telemetry (server)

- `preview_3d.viewer_path_initialized` — inspection stamped native GLB viewer metadata.
- `preview_3d.poster_generated` — poster + `preview_3d` metadata merged after thumbnail job.
- `preview_3d.preview_pipeline_skipped` — thumbnail pipeline skipped for registry 3D types.
- `preview_3d.client_event` — optional browser posts (`model_viewer_error`, `model_viewer_retry`, `model_viewer_open_full`, `model_viewer_fallback_active`).

## Admin / support

- Admin asset console receives **`preview_3d_support`**: status, booleans for viewer/poster presence, `poster_stub`, `viewer_enabled`, and `failure_message` / `skip_reason` — **not** raw storage keys.
