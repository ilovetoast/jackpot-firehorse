# GLB realtime preview — production notes (Phase 5D)

## Scope

- **Realtime in-browser preview** is **GLB-only** via `<model-viewer>` when the registry type is `model_glb`, `DAM_3D` is enabled, and signed poster/viewer CDN URLs resolve.
- **OBJ, FBX, BLEND, and glTF with external resources** are **not** served as interactive realtime previews in the product UI today. Those formats may still appear in the library with **raster poster thumbnails** (or placeholders) where the pipeline supports them; full interactive viewing requires canonical GLB delivery (Blender conversion on workers when `DAM_3D` and optional conversion are enabled — see Phase 6 below).

## Configuration

- **`DAM_3D`**: `config/dam_3d.php` (`env('DAM_3D', false)` → `dam_3d.enabled`) gates registry 3D thumbnail generation and the GLB `<model-viewer>` path. When disabled, GLB assets do not get interactive viewer URLs even if metadata contains a native viewer key.
- **Posters**: `metadata.preview_3d.poster_path` holds the **raster** poster object key; the API exposes `preview_3d_poster_url` and `preview_3d_revision` (opaque) for cache busting — never raw S3 keys in tenant JSON.

## Browsers

- Use a **current Chromium, Firefox, or Safari** with **WebGL** enabled. `<model-viewer>` relies on standard web platform features; unsupported or locked-down environments fall back to the **poster / thumbnail** path.

## CDN, signed URLs, and CORS

- Delivery runs through **`AssetDeliveryService`** and CloudFront (non-local). Viewer and poster URLs must be **reachable by the browser** from the app origin (typical same-site CDN host).
- **CORS** on the bucket / distribution must allow **GET** for model and image fetches the viewer issues. The app sets **`crossorigin="anonymous"`** on `<model-viewer>` so the browser performs a CORS-enabled fetch (required for WebGL); the CDN must respond with a matching **`Access-Control-Allow-Origin`** (often your staging/prod site origin, or `*` during bring-up). If signing fails, structured logs use `preview_3d.signed_url_resolution_failed` or `preview_3d.signed_url_empty` (see `Preview3dDeliveryUrls`).

## Operational limits

- Prefer **GLB files under ~50 MB** for a smooth UX; very large models may fail on low-memory clients and will **fall back** to poster mode after a viewer error.
- Heavy scenes may increase GPU memory; treat large assets like large images for support expectations.

## Telemetry (server)

- `preview_3d.viewer_path_initialized` — inspection stamped native GLB viewer metadata.
- `preview_3d.poster_generated` — poster + `preview_3d` metadata merged after thumbnail job.
- `preview_3d.preview_pipeline_skipped` — thumbnail pipeline skipped for registry 3D types.
- `preview_3d.client_event` — optional browser posts (`model_viewer_error`, `model_viewer_retry`, `model_viewer_open_full`, `model_viewer_fallback_active`).

## Phase 6 — Blender-backed posters (workers only)

- **Web servers do not need Blender.** Install **Blender 4.5.3 LTS** only on **workers** that run `GenerateThumbnailsJob` (same queues as heavy images: typically `images-heavy`). Install from the **official blender.org linux-x64 tarball**, symlink to **`/usr/local/bin/blender`** — **do not** use Ubuntu **`apt install blender`** for this pipeline (it often installs **3.0.x** and is unsupported here). Full steps: [environments/BLENDER_DAM_3D_INSTALL.md](environments/BLENDER_DAM_3D_INSTALL.md).
- **Binary env (workers):** set **`DAM_3D_BLENDER_BINARY=/usr/local/bin/blender`** (existing env key only). Quick verification: `/usr/local/bin/blender --version` and `/usr/local/bin/blender -b --python-expr "print('Blender OK')"`.
- **Behaviour:** When `DAM_3D=true` and `real_render_enabled` is true (default in config), workers **try** `resources/blender/render_model_preview.py` headless for **GLB, STL, OBJ, FBX, BLEND**. On success, raster posters are real renders; `metadata.preview_3d.debug.poster_stub=false` and `blender_used=true`. On missing Blender, timeout, or import failure, the **stub poster** path remains (`poster_stub=true`) and uploads are **never** failed because of preview work.
- **Conversion (optional):** `conversion_enabled` in `config/dam_3d.php` (default `false`). When enabled, Blender may write `previews/model_3d_converted.glb` and set `viewer_path` for converted assets; native GLB continues to use the original object key as viewer path.
- **Diagnostics:** `php artisan dam:3d:diagnose` prints DAM_3D state, binary presence, Blender version, temp writability, queue hints, and size/time caps.
- **Formats by level:** GLB — viewer + rendered poster; STL — rendered poster (viewer only after conversion); OBJ — best-effort poster (sidecars may limit fidelity); FBX / BLEND — Blender required for real poster; without Blender, stub poster only.
- **Troubleshooting:** Blender missing (expect stub); import errors in stderr summary (admin metadata); render timeout (raise `max_render_seconds` / worker timeout); GLTF external `.bin` missing; signed URL / CORS (unchanged from above); oversized models (`max_server_render_bytes` / `max_upload_bytes` caps).

## Admin / support

- Admin asset console receives **`preview_3d_support`**: status, booleans for viewer/poster presence, `poster_stub`, `viewer_enabled`, and `failure_message` / `skip_reason` — **not** raw storage keys.
