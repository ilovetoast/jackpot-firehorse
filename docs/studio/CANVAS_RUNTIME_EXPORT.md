# Studio composition canvas-runtime video export

This document describes the **additive** architecture for exporting Studio Builder compositions so the MP4 matches editor-visible output (text, shapes, gradients, masks, opacity, transforms, z-order, raster layers, and future motion). It complements the existing **legacy bitmap** pipeline (`StudioCompositionVideoExportService`).

---

## Why FFmpeg alone cannot solve live text and masks here

FFmpeg’s filter graph is a **limited, declarative** video pipeline (scale, pad, overlay, drawtext with simple fonts, colorchannelmixer, etc.). It does **not** execute the editor’s **scene graph**:

- **Typography**: the editor uses web fonts (`@font-face`, Brand DNA uploads), line breaking, `autoFit`, `-webkit-text-stroke`, alignment, and multi-line boxes. Re-expressing that as `drawtext`/`subtitles` per layer is a **parallel renderer**, not “the same” as the editor, and breaks on the first custom font or layout edge case.
- **Masks / clipping**: the editor uses CSS `mask-image`, overflow clipping, and group semantics. FFmpeg has `alphamask` and geq-style hacks but **no drop-in port** of arbitrary CSS mask stacks tied to layer ids.
- **Blend modes / filters**: CSS `mix-blend-mode` and future effects are not 1:1 with FFmpeg blend expressions without a full translation layer (again a second implementation).
- **Source of truth**: `document_json` is authored for **browser layout**. The only robust way to guarantee parity is to **run the same layout engine** (Chromium + DOM/canvas) and **sample pixels**, then let FFmpeg handle **encoding, muxing, and temporal compositing** over the base video.

So: FFmpeg remains essential for **H.264/AAC**, timeline cuts, and **merging** base video + overlay sequence—but it cannot *be* the editor renderer.

---

## Technical implementation plan

### Phase 0 (done in repo): contracts, routing, queues, scaffold

1. **`render_mode`** column on `studio_composition_video_export_jobs`: `legacy_bitmap` (default) | `canvas_runtime`.
2. **API**: `POST .../studio/video-export` accepts optional `render_mode`; status payload includes `render_mode`.
3. **`StudioCompositionVideoExportOrchestrator`**: dispatches to legacy service vs canvas-runtime service.
4. **Queues**: `ProcessStudioCompositionVideoExportJob` uses `StudioVideoQueue::heavy()` for legacy; `StudioCanvasExportQueue::heavy()` for canvas-runtime (defaults to the same Redis queue until `QUEUE_VIDEO_HEAVY_STUDIO_CANVAS_QUEUE` is set).
5. **Signed internal render URL**: `GET /internal/studio/composition-export-render/{exportJob}` (middleware `web`, `signed`) serves Inertia page `StudioExport/CompositionExportRender` with **no editor chrome** (`app.jsx` treats `StudioExport/*` like experience pages).
6. **`CompositionRenderPayloadFactory`**: versioned JSON (`version: 1`, dimensions, fps, duration, sorted layers, background hint, ids) plus **`brand_context`** and derived **`fonts`** metadata for the export surface.
7. **`StudioCompositionCanvasRuntimeVideoExportService`**: when `STUDIO_VIDEO_CANVAS_RUNTIME_EXPORT_ENABLED=true`, runs **`scripts/studio-canvas-export.mjs`** (Playwright), then **`StudioCompositionCanvasRuntimeFfmpegMerger`** (FFmpeg) + **`StudioCompositionVideoExportMp4Publisher`** in the same job. The job stays **`processing`** until merge + publish succeed, then **`complete`** with **`meta_json.canvas_runtime_capture.ffmpeg_merge_pending=false`**. On merge failure the job is **`failed`** with **`meta_json.canvas_runtime_merge_diagnostics`** (redacted argv, stderr tails, validation codes).

### Phase 1: headless frame capture + FFmpeg merge (implemented)

1. **Worker image / dependencies**: Node LTS + **`playwright`** on the **video-heavy** (or dedicated canvas) worker; `npm ci` in `jackpot/`; run **`npx playwright install --with-deps chromium`** in CI/AMI bake (or `PLAYWRIGHT_BROWSERS_PATH` cache). In build/CI, **`npx playwright install --with-deps --dry-run chromium`** validates the CLI and planned browser + OS deps **without installing** (see [Production worker software — Node.js / Playwright](../environments/PRODUCTION_WORKER_SOFTWARE.md#nodejs--playwright-video-heavy--studio)).
2. **Node driver**: `jackpot/scripts/studio-canvas-export.mjs`

   - CLI: `--url`, `--output-dir`, `--fps`, `--duration-ms`, `--width`, `--height`, `--export-job-id`, plus optional timeouts / `--frame-settle-ms` / `--device-scale-factor` (see service + script header).
   - Launch Chromium headless; `page.goto(signedUrl)`; wait for `__COMPOSITION_EXPORT_BRIDGE__.getState().ready`.
   - For each frame index: `setTimeMs(frameTimeMs)`, double `requestAnimationFrame` + deterministic settle delay, screenshot **`[data-jp-composition-scene-root]`** to `frame_%0Nd.png`.
   - Writes **`capture-manifest.json`**; on failure writes **`capture-diagnostics.json`** and optional **`failure-*.png`**.

3. **PHP service** (`StudioCompositionCanvasRuntimeVideoExportService`):

   - Assert feature flag; resolve signed URL via `URL::temporarySignedRoute`; create `storage/app/studio-canvas-runtime/{jobId}/run-*`.
   - **`DefaultStudioCanvasRuntimePlaywrightInvoker`** runs `Symfony\Component\Process` (`config/studio_video.php` timeouts + node binary path).
   - **FFmpeg merge**: **`StudioCompositionCanvasRuntimeFfmpegMerger`** reads **`meta_json.canvas_runtime_capture.working_directory`** + manifest (`fps`, `duration_ms`, `frame_filename_pattern`, frame counts, dimensions). Base video selection, trim, duration cap, pad color, and audio gating match **`StudioCompositionVideoExportMediaHelper`** + legacy rules (`include_audio` + layer `timeline.muted` + `ffprobe` audio presence). **`DefaultStudioCanvasRuntimeFfmpegProcessInvoker`** runs FFmpeg (configurable timeout, x264 preset/CRF, pixel format). **`StudioCompositionVideoExportMp4Publisher`** writes the MP4 and dispatches **`ProcessAssetJob`** (same as legacy export).

### Merge diagnostics schema (`meta_json.canvas_runtime_merge_diagnostics`)

- **`schema`**: `studio_canvas_runtime_merge_diagnostics_v1`
- **`phase`**: `validation_failed` | `ffmpeg_failed` | `ffmpeg_finished` | `complete`
- **`failure_code`** (on service-level merge failure): e.g. `canvas_runtime_merge_no_video_layer`
- **`ffmpeg_argv_redacted`**: argv with capture dir / temp paths replaced by placeholders
- **`filter_complex_summary`**, **`exit_code`**, **`stderr_tail`**, **`stdout_tail`**, **`encode_wall_clock_ms`**
- **`audio_policy`**, **`duration_policy`**, **`merge_visual_policy`**: human-readable policy strings (no secrets)

### Capture failure envelope (`meta_json.canvas_runtime_diagnostics` on capture failure)

- **`schema`**: `studio_canvas_runtime_capture_diagnostics_v1`
- **`phase`**: `capture_failed` (or `capture_complete` on success path before merge)
- **`failure_code`**: same string as `error_json.code` / `error_json.failure_code` where applicable

### Retention (`meta_json.canvas_runtime_retention`)

- **`schema`**: `studio_canvas_runtime_retention_v1`
- **Success**: row is marked **`complete`** and **`output_asset_id`** is set **before** optional deletion of `frame_*.png` (when `STUDIO_VIDEO_CANVAS_RUNTIME_MERGE_DELETE_PNG_FRAMES_AFTER_SUCCESS=true`). `capture-manifest.json` on disk is **not** deleted by the app; manifest summary remains in `meta_json.canvas_runtime_diagnostics.manifest`. Working directory path is recorded for ops; manual pruning of `storage/app/studio-canvas-runtime/{jobId}/...` is an ops concern.
- **Failure (merge/publish)**: PNG sequence and working directory are **left on disk** (`png_frames_deleted_after_failure: false`). `meta_json` retains capture + merge diagnostics for debugging.
- **`policy_note`**: optional operator-facing text from `config('studio_video.canvas_runtime_retention_policy_note')` / `STUDIO_VIDEO_CANVAS_RUNTIME_RETENTION_POLICY_NOTE`.

### Repair (`meta_json.canvas_runtime_repair`)

Written only when **`StudioCompositionCanvasRuntimeVideoExportService::repairMergePublish`** completes successfully (Artisan reconcile `--execute`). Fields: `schema`, `repaired_at`, `repair_classification`, `repair_context`.

### Phase 2: shared runtime with editor

1. **Extract** a package-like module under `resources/js/Pages/Editor/studioCompositionRuntime/` (or `resources/js/studio/compositionRuntime/`):

   - `CompositionScene` — pure props: `CompositionRenderPayloadV1` + `timeMs`.
   - Renders **all layer types** the editor supports, with **no** AssetEditor state (no undo, selection, panels).
   - Uses the same layout math as today’s canvas DOM (factor shared hooks from `AssetEditor.tsx` incrementally).

2. **Editor preview** optionally instantiates the same `CompositionScene` inside the existing shell to guarantee one codepath (feature-flag per tenant if risky).

3. **Internal page** imports only `CompositionScene` + font preload bootstrap.

### Phase 3: motion / transitions

1. Extend payload `timing` with keyframes; `setTimeMs` drives CSS variables or Web Animations API **paused** timelines scrubbed by `currentTime`.

---

## Queue classification recommendation

| Queue | Intent | Workers |
|-------|--------|---------|
| `video-heavy` | Legacy Studio export, long FFmpeg, animation finalize | High RAM, long timeout |
| `video-heavy-studio-canvas` (optional) | Playwright + PNG sequence + FFmpeg merge | **Higher RAM**, Chromium deps, same long timeout, **lower concurrency** (1–2 processes) |

Default: canvas jobs **reuse** `video-heavy` so nothing breaks until ops add `QUEUE_VIDEO_HEAVY_STUDIO_CANVAS_QUEUE` and a Horizon supervisor.

---

## Data contract / schema changes

- **DB**: `studio_composition_video_export_jobs.render_mode`.
- **HTTP**: `render_mode` on create + status.
- **Canonical payload**: `CompositionRenderPayloadFactory` + TS mirror `compositionRenderContract.ts`. **Bump `version`** when:
  - adding fields,
  - changing sort order semantics,
  - changing time base,
  - or changing font resolution rules.

Future: persist a **snapshot** of the payload on the job row (`render_payload_json`) so export reproduces exact point-in-time even if composition is edited mid-flight.

---

## Current production behavior (canvas_runtime)

This section documents **what the shipped code does today**, not future aspirations.

### Render path summary

1. Queue worker runs **`ProcessStudioCompositionVideoExportJob`** → **`StudioCompositionVideoExportOrchestrator`** routes **`render_mode=canvas_runtime`** to **`StudioCompositionCanvasRuntimeVideoExportService`** (legacy stays on **`StudioCompositionVideoExportService`**).
2. **Playwright** (`scripts/studio-canvas-export.mjs`) opens the signed internal render URL, waits for **`window.__COMPOSITION_EXPORT_BRIDGE__`**, steps time, saves **`frame_%0Nd.png`** + **`capture-manifest.json`** under `storage/app/studio-canvas-runtime/{jobId}/run-*`.
3. **Merge** reads manifest + frames + primary base video (same selection/trim/duration rules as legacy via **`StudioCompositionVideoExportMediaHelper`**), runs FFmpeg (overlay + shortest), then **publish** via **`StudioCompositionVideoExportMp4Publisher`** (same durable asset path as legacy).
4. Job ends **`complete`** with **`ffmpeg_merge_pending=false`** and **`output_asset_id`** set, or **`failed`** with structured **`error_json`** + diagnostics. **There is no silent fallback** to legacy export.

### Visual policy (today)

- Playwright captures the **full** `CompositionScene` raster for each frame, **including** the HTML5 `<video>` layer. Those PNGs are **opaque** (normal screenshots).
- FFmpeg **overlays** that sequence on top of the **scaled/padded** base video stream so **trim, duration caps, and audio** stay tied to the same base asset the legacy exporter uses. Pixels match the captured PNG timeline; the base decode exists for **timing/audio linkage** and shared trim rules (see `merge_visual_policy` in merge diagnostics).

### Audio policy (today)

Same rule family as legacy: mux **AAC** from the base asset’s first audio stream only when **`meta_json.include_audio`** is true, the primary video layer is **not** `timeline.muted`, and **ffprobe** reports an audio stream; otherwise **`-an`** (silent video).

### Duration policy (today)

Target output length is **`output_duration_s`** from **`StudioCompositionVideoExportMediaHelper::computeTrimAndOutputDuration()`** (min of trimmed source availability and composition `studio_timeline.duration_ms`). The merger validates manifest frame counts / `duration_ms` / fps against that target within an explicit tolerance; mismatches fail loudly (no hidden time-stretch).

### Readiness contract

Export render page preloads fonts/rasters and sets bridge **`ready`** only when its internal checks pass; Playwright waits on that contract. If readiness fails, capture fails and the job is **`failed`** with diagnostics—**no merge** is attempted.

---

## Reconciler (repair tooling)

**Command**: `php artisan studio:reconcile-canvas-runtime-video-exports`

- **Default**: **dry-run** (prints one line per candidate + classification counts). **No mutations** without **`--execute`**.
- **Default scope**: only rows with **`meta_json.canvas_runtime_capture.ffmpeg_merge_pending = true`**. Use **`--full-scan`** to list *all* `canvas_runtime` jobs (still classified conservatively).
- **Filters**: `--id=`, `--tenant-id=`, `--brand-id=`, `--since=YYYY-MM-DD`, `--until=YYYY-MM-DD`.
- **Repairs only** when classification is:
  - **`repairable_stuck_complete_merge_pending`**: `status=complete`, merge pending, **no** `output_asset_id`, capture dir + manifest path **look consistent** on disk.
  - **`repairable_processing_merge_pending`**: worker died after capture; same artifact checks.
- **Never repairs** `ambiguous_*` (e.g. complete + pending **but** `output_asset_id` set, or missing artifacts, or bad manifest JSON)—those are reported only.
- **Implementation**: `--execute` calls **`StudioCompositionCanvasRuntimeVideoExportService::repairMergePublish`** (merge+publish **only**, **no** Playwright re-run). Requires **`STUDIO_VIDEO_CANVAS_RUNTIME_EXPORT_ENABLED=true`** on the host running the command.

### Examples

```bash
# Dry-run: inconsistent rows only (merge_pending true)
php artisan studio:reconcile-canvas-runtime-video-exports --tenant-id=1

# Inspect one job
php artisan studio:reconcile-canvas-runtime-video-exports --id=12345

# Apply merge-only repair (operator intent required)
php artisan studio:reconcile-canvas-runtime-video-exports --execute --id=12345
```

---

## API debug affordance (status payload)

`GET .../studio/video-export/{exportJobId}` includes, for **`render_mode=canvas_runtime`**, a small **`canvas_runtime_debug`** object:

- **`export_phase`**: human-readable coarse phase (e.g. `processing_merge`, `inconsistent_complete_merge_pending`, `complete`, `failed`).
- **`ffmpeg_merge_pending`**, **`capture_phase`**, flags for presence of capture/merge/repair/retention blobs in `meta_json`.

No secrets or signed URLs are added here.

---

## Failure / debug strategy

- **Never** auto-fallback legacy → canvas or canvas → legacy without explicit client `render_mode` (product decision).
- **Never** auto-repair stuck rows from the queue worker: only the **Artisan reconciler** with **`--execute`** mutates jobs (operator intent).
- On failure, always set:
  - `error_json.code`, **`error_json.failure_code`** (duplicate for stable clients), `error_json.message`, `error_json.debug` (includes **`phase`** where applicable),
  - `meta_json.canvas_runtime_diagnostics` / **`canvas_runtime_merge_diagnostics`** / **`canvas_runtime_retention`** as appropriate.
- **Logging**: structured `Log::warning` / `info` with `export_job_id`, `composition_id`, `tenant_id`.

---

## Staging / production rollout checklist

1. **Feature flag**: keep **`STUDIO_VIDEO_CANVAS_RUNTIME_EXPORT_ENABLED=false`** until workers are ready; enable deliberately per environment.
2. **Binaries on workers**: `ffmpeg`, `ffprobe`, `node`, Playwright Chromium (**`npx playwright install --with-deps chromium`** from `jackpot/` after `npm ci` in image bake). Preflight with **`npx playwright install --with-deps --dry-run chromium`**; full checklist: [PRODUCTION_WORKER_SOFTWARE.md](../environments/PRODUCTION_WORKER_SOFTWARE.md#nodejs--playwright-video-heavy--studio).
3. **Env**: set `STUDIO_VIDEO_FFMPEG_BINARY`, `STUDIO_VIDEO_FFPROBE_BINARY`, canvas timeouts, optional `QUEUE_VIDEO_HEAVY_STUDIO_CANVAS_QUEUE`, merge timeout / x264 / optional PNG delete envs (see `config/studio_video.php` and `.env.example`).
4. **Queues**: run **video-heavy** (or dedicated canvas) Horizon workers with RAM headroom; Playwright is memory-hungry.
5. **Disk**: PNG sequences are one file per frame; long 1080p exports need **large ephemeral disk** on workers.
6. **Smoke test**: one short composition export with `render_mode=canvas_runtime`; confirm job **`complete`**, asset visible, **`ffmpeg_merge_pending=false`**, `canvas_runtime_debug.export_phase=complete`.
7. **First failures**: inspect `error_json`, `meta_json.canvas_runtime_diagnostics`, `meta_json.canvas_runtime_merge_diagnostics`, structured logs; use reconciler **dry-run** to see stuck rows before **`--execute`**.

---

## Known limitations (today)

- No multi-track audio mixing; no voiceover timeline beyond base mux rules.
- No transparent “video hole” in PNGs—scene capture includes HTML video pixels.
- Chromium/font/video seek determinism can still drift in edge cases (documented in diagnostics, not silently corrected).
- Historical rows **`complete` + `ffmpeg_merge_pending`** from earlier interim behavior: use the **reconciler** (or manual status fix), not automatic worker healing.

---

## Test plan (editor ↔ export parity)

1. **Golden fixtures**: 3–5 frozen `CompositionRenderPayloadV1` JSON files + expected PNG frame(s) at `t=0`, `t=mid`, `t=end`.
2. **Headless**: Playwright test navigates signed URL (generate in test via `URL::temporarySignedRoute`), waits for ready, compares screenshot hash to golden (threshold for antialiasing).
3. **FFmpeg**: integration test overlays 10 identical PNGs on solid color video, asserts output duration and stream map.
4. **Regression**: legacy `render_mode` default unchanged; existing export tests still pass.
5. **Queue**: unit test that job constructor selects `StudioCanvasExportQueue` when `render_mode=canvas_runtime`.

---

## Files added / touched (reference)

| Area | Path |
|------|------|
| Enum | `app/Services/Studio/StudioCompositionVideoExportRenderMode.php` |
| Orchestrator | `app/Services/Studio/StudioCompositionVideoExportOrchestrator.php` |
| Canvas capture + merge | `app/Services/Studio/StudioCompositionCanvasRuntimeVideoExportService.php` |
| FFmpeg merge | `app/Services/Studio/StudioCompositionCanvasRuntimeFfmpegMerger.php` |
| FFmpeg invoker (test seam) | `app/Contracts/StudioCanvasRuntimeFfmpegProcessInvokerContract.php`, `app/Services/Studio/DefaultStudioCanvasRuntimeFfmpegProcessInvoker.php` |
| Shared media rules | `app/Services/Studio/StudioCompositionVideoExportMediaHelper.php` |
| MP4 publish (legacy + canvas) | `app/Services/Studio/StudioCompositionVideoExportMp4Publisher.php` |
| Playwright invoker | `app/Contracts/StudioCanvasRuntimePlaywrightInvokerContract.php`, `app/Services/Studio/DefaultStudioCanvasRuntimePlaywrightInvoker.php` |
| Node capture script | `scripts/studio-canvas-export.mjs`, `scripts/studio-canvas-export.test.mjs` |
| Payload factory | `app/Services/Studio/CompositionRenderPayloadFactory.php` |
| Queue helper | `app/Support/StudioCanvasExportQueue.php` |
| Internal page | `app/Http/Controllers/Internal/StudioCompositionExportRenderController.php` |
| Route | `routes/web.php` |
| Job | `app/Jobs/ProcessStudioCompositionVideoExportJob.php` |
| API | `app/Http/Controllers/Editor/EditorCompositionStudioVideoController.php` |
| Config | `config/studio_video.php` |
| Migration | `database/migrations/2026_04_25_120000_add_render_mode_to_studio_composition_video_export_jobs_table.php` |
| Inertia | `resources/js/Pages/StudioExport/CompositionExportRender.tsx` |
| TS contract | `resources/js/Pages/StudioExport/compositionRenderContract.ts` |
| App shell | `resources/js/app.jsx` |
| Client API | `resources/js/Pages/Editor/editorStudioVideoBridge.ts` |
| Reconciler command | `app/Console/Commands/ReconcileStudioCanvasRuntimeVideoExportsCommand.php` |
| Job classifier | `app/Services/Studio/StudioCanvasRuntimeExportJobClassifier.php` |
| API diagnostics helper | `app/Support/StudioCanvasRuntimeExportJobDiagnostics.php` |
