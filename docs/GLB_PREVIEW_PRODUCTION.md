# GLB realtime preview — production notes (Phase 5D)

## Scope

- **Realtime in-browser preview** is **GLB-only** via `<model-viewer>` when the registry type is `model_glb`, **`DAM_3D_REALTIME_VIEWER`** (or `DAM_3D` when the former is unset) is enabled for the web app, and signed poster/viewer CDN URLs resolve.
- **OBJ, FBX, BLEND, and glTF with external resources** are **not** served as interactive realtime previews in the product UI today. Those formats may still appear in the library with **raster poster thumbnails** (or placeholders) where the pipeline supports them; full interactive viewing requires canonical GLB delivery (Blender conversion on workers when `DAM_3D` and optional conversion are enabled — see Phase 6 below).

## Configuration

- **`DAM_3D`**: `config/dam_3d.php` (`env('DAM_3D', false)` → `dam_3d.enabled`) gates registry 3D **thumbnail / poster** generation in `GenerateThumbnailsJob` (workers).
- **`DAM_3D_REALTIME_VIEWER`**: `dam_3d.realtime_viewer_enabled` gates the **browser** `<model-viewer>` UI for native GLB. When unset, it defaults to the same boolean as `DAM_3D`. Set **`DAM_3D_REALTIME_VIEWER=true`** on **web-only staging** when workers use `DAM_3D=false` but you still want interactive GLB (signed URLs + CDN CORS must work). When both are false, the client does not mount `<model-viewer>` even if `preview_3d` metadata exists.
- **Posters**: `metadata.preview_3d.poster_path` holds the **raster** poster object key; the API exposes `preview_3d_poster_url` and `preview_3d_revision` (opaque) for cache busting — never raw S3 keys in tenant JSON.

## Browsers

- Use a **current Chromium, Firefox, or Safari** with **WebGL** enabled. `<model-viewer>` relies on standard web platform features; unsupported or locked-down environments fall back to the **poster / thumbnail** path.

## CDN, signed URLs, and CORS

- Delivery runs through **`AssetDeliveryService`** and CloudFront (non-local). Viewer and poster URLs must be **reachable by the browser** from the app origin (typical same-site CDN host).
- **CORS** on the bucket / distribution must allow **GET** for model and image fetches the viewer issues. The app sets **`crossorigin="anonymous"`** on `<model-viewer>` so the browser performs a CORS-enabled fetch (required for WebGL); the CDN must respond with a matching **`Access-Control-Allow-Origin`** (often your staging/prod site origin, or `*` during bring-up). If signing fails, structured logs use `preview_3d.signed_url_resolution_failed` or `preview_3d.signed_url_empty` (see `Preview3dDeliveryUrls`).

### AWS — fix “No Access-Control-Allow-Origin” for `cdn-*` vs `staging-*` (same pattern for audio / any cross-origin media)

Browsers **do not** let JavaScript read the Chrome CORS console string, but the app posts **`preview_3d.client_event`** with optional **`page_origin`** and **`model_origin`**; when they differ, **`likely_cross_origin_model: true`** flags the common CDN CORS case for log grep.

1. **S3 bucket CORS** (origin that serves the files, often behind CloudFront): add a rule allowing **`GET`** / **`HEAD`** from your app origins, e.g. `https://staging-jackpot.velvetysoft.com` (and production when ready). Example shape:

```xml
<CORSConfiguration>
  <CORSRule>
    <AllowedOrigin>https://staging-jackpot.velvetysoft.com</AllowedOrigin>
    <AllowedMethod>GET</AllowedMethod>
    <AllowedMethod>HEAD</AllowedMethod>
    <AllowedHeader>*</AllowedHeader>
    <ExposeHeader>ETag</ExposeHeader>
  </CORSRule>
</CORSConfiguration>
```

2. **CloudFront**: S3 CORS alone is not always visible to the browser if CloudFront strips or overrides headers. Prefer a **Response headers policy** (or legacy custom/error response headers) on the **viewer distribution** so **`Access-Control-Allow-Origin`** (or dynamic via **Origin custom header** + **Vary: Origin**) is returned on **GET/HEAD** for object paths (`*.glb`, audio, etc.). Invalidate or wait for TTL after changes.

3. **Audio / Web Audio / fetch** to the same CDN host: use the **same CORS rule** — any cross-origin **readable** response (ArrayBuffer, decodeAudioData, `fetch` + CORS mode) needs **`Access-Control-Allow-Origin`** (and often **`Access-Control-Allow-Headers`** if you send custom headers).

## Operational limits

- Prefer **GLB files under ~50 MB** for a smooth UX; very large models may fail on low-memory clients and will **fall back** to poster mode after a viewer error.
- Heavy scenes may increase GPU memory; treat large assets like large images for support expectations.

## Telemetry (server)

- `preview_3d.viewer_path_initialized` — inspection stamped native GLB viewer metadata.
- `preview_3d.poster_generated` — poster + `preview_3d` metadata merged after thumbnail job.
- `preview_3d.preview_pipeline_skipped` — thumbnail pipeline skipped for registry 3D types.
- `preview_3d.client_event` — browser posts (`model_viewer_error`, `model_viewer_retry`, `model_viewer_open_full`, `model_viewer_fallback_active`). When the client sends **`page_origin`** and **`model_origin`**, the log includes **`likely_cross_origin_model`** (true when they differ) to correlate CDN CORS issues without relying on JS reading the blocked response.

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
