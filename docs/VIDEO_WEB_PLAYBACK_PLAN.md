# Plan: browser-safe video playback (FFmpeg + heavy queue)

## Problem

The DAM accepts several **video** containers (`config/file_types.php` → `video`: e.g. MP4, MOV, AVI, MKV, WebM, M4V). Today:

- **Hover / grid “quick preview”** is a **short, muted H.264 MP4** produced by `GenerateVideoPreviewJob` via `VideoPreviewGenerationService` (2–4 s clip, ~320 px box). That derivative is **not** a substitute for full-length playback (no audio, truncated duration).
- **Drawer / lightbox full playback** uses `AssetController::jsonVideoStreamUrl()`, which prefers a **signed URL to the original** (`AssetVariant::ORIGINAL`). That is correct when the source is already **browser-streamable** (typical H.264/AAC in MP4/M4V).

Many uploads are **valid files but poor `<video>` citizens**: AVI (legacy codecs), MKV (anything), MPEG/PS, some MOV (ProRes, DNxHD, HEVC), WebM (VP9 / limited Safari paths), odd pixel formats, etc. Users see a black player, spinner forever, or “format not supported” even though ingestion succeeded.

**Goal:** After upload, automatically produce a **full-length, browser-first derivative** where needed, store it like other previews, and have the **same JSON playback endpoint** prefer that file—without blocking thumbnails or the rest of the main pipeline.

---

## Recommended output (easiest reliable default)

Use **one** canonical web derivative unless probes justify a cheaper path:

| Field | Recommendation | Rationale |
|--------|------------------|-----------|
| Container | **MP4** (`video/mp4`) | Broadest `<video>` + range-request support |
| Video | **H.264** (`libx264`), **yuv420p**, **+faststart** (`-movflags +faststart`) | Chrome, Firefox, Safari, Edge |
| Audio | **AAC-LC** (`aac`, e.g. `-b:a 128k` or copy when already AAC in a friendly container) | Matches H.264 MP4 ecosystem |
| Resolution cap | e.g. **max 1920 px** long edge (configurable) | Controls CPU, RAM, and storage; 4K sources still preview well |
| Frame rate | cap e.g. **60 fps** or preserve with sane upper bound | Avoid pathological high-fps sources |
| Duration cap (optional) | e.g. transcode first **N minutes** + flag “trimmed” | Protects workers from multi-hour sources; product decision |

**Fast path (optional phase 2):** If `ffprobe` reports streams already **H.264 + yuv420p + AAC** inside a seekable MP4/MOV, use **`ffmpeg -c copy -movflags +faststart`** (remux only) when container is close enough—**much** cheaper than re-encode. Fall back to full transcode on any probe/ mux failure.

---

## Architecture (mirror audio web playback)

The existing **audio** pattern is the template:

| Concern | Audio reference | Video analogue |
|---------|-----------------|----------------|
| “Should we transcode?” | `AudioPlaybackOptimizationService::decideStrategy()` | New `VideoWebPlaybackOptimizationService` (or split decision + encoder) |
| Heavy work off the images chain | `GenerateAudioWebPlaybackJob` dispatched **beside** the chain from `ProcessAssetJob` | New `GenerateVideoWebPlaybackJob` dispatched the same way |
| Queue routing by weight | `AudioPipelineQueueResolver` + `assets.audio.heavy_queue_min_bytes` | New `VideoWebPlaybackQueueResolver` (or extend a small `VideoPipelineQueueResolver`) |
| Storage path | `metadata.audio.web_playback_path` etc. | `metadata.video.web_playback_path`, `web_playback_size_bytes`, `web_playback_reason`, `web_playback_codec` |
| Delivery | `AssetVariant::AUDIO_WEB` + `AssetVariantPathResolver` + `AssetDeliveryService` | New enum case e.g. `AssetVariant::VIDEO_WEB` (value `video_web`) |
| Controller preference | `audioPlaybackUrl()` prefers derivative when present | `jsonVideoStreamUrl()` prefers **VIDEO_WEB** when present and policy says “use derivative”, else **ORIGINAL** (current behaviour) |

**Do not** put full-length transcode **inside** `Bus::chain([...])` after `GenerateVideoPreviewJob`: that would delay finalize/promote and tie up the images worker for hours. Keep parity with audio: **parallel dispatch** after the main chain is queued.

---

## Queue and worker designation (“heavy job”)

Config already defines:

- `config('queue.video_heavy_queue')` → default `video-heavy` (`config/queue.php`).
- Sail `compose.yaml` includes **`queue_video_heavy`** with **long timeout** (`--timeout=14400`, etc.) — appropriate for FFmpeg graphs on large files.

**Routing proposal:**

1. **Default:** dispatch `GenerateVideoWebPlaybackJob` to **`video-heavy`** whenever a transcode is required (simplest operationally: one supervisor class, predictable RAM/timeout).
2. **Optional refinement:** if the derivative is **remux-only** (`-c copy`) and source &lt; threshold, allow **`video_light_queue`** or even `images-heavy`—only after metrics show benefit.
3. **Constructor-driven timeout** (same pattern as `GenerateAudioWebPlaybackJob`): set `$this->timeout` from `config('assets.video.web_playback_job_timeout_seconds')` with a **heavier** tier when `size_bytes` (or duration from metadata) exceeds a ceiling.

Ensure **`AssetProcessingBudgetService`** is consulted at the **start of handle()** (like `GenerateVideoPreviewJob`) so tiny workers do not download multi-GB sources.

---

## When to transcode (decision matrix)

**Phase 1 (extension / MIME heuristics — ship first):**

- Always offer derivative for containers commonly broken in browsers: **`avi`, `mkv`, `mpeg`, `mpg`** (and add any new extensions you allow later).
- For **`webm`**: transcode or remux based on product (Safari historically weaker on VP9; may still want MP4 H.264 for “universal” playback).
- For **`mov`**: optional “always transcode” flag, or defer to Phase 2 probe (MOV is often fine on Safari when H.264).

**Phase 2 (ffprobe-driven):**

- Parse video codec, pixel format, audio codec, profile/level.
- **Native playback** when: e.g. H.264 + yuv420p + AAC in MP4/M4V (and optionally “safe” MOV), and file size below a “stream copy OK” threshold.
- **Transcode** when: HEVC, ProRes, MPEG-2, VP9 (if targeting Safari-first MP4), non-420 chroma, etc.

Persist **`metadata.video.web_playback_strategy`** (`native_skipped` | `remux` | `transcode`) for support and UI copy.

---

## Implementation checklist (engineering)

1. **Config** — `config/assets.php` section `video.web_playback`: `enabled`, `force_extensions`, probe timeouts, max dimension, bitrate floors/ceilings, job timeouts, `heavy_queue_min_bytes` (if splitting queues).
2. **Enum + resolver** — `AssetVariant::VIDEO_WEB`, `AssetVariantPathResolver::resolveVideoWebPlaybackPath()` → e.g. `{versionBase}/previews/video_web.mp4` (same family as `video_preview.mp4`).
3. **Service** — download source (reuse `VideoPreviewGenerationService::downloadSourceToTemp` patterns), run FFmpeg, upload to tenant bucket, merge `metadata.video.*` on success.
4. **Job** — `GenerateVideoWebPlaybackJob` implements `ShouldQueue`, **`$this->onQueue(config('queue.video_heavy_queue'))`** (or resolver), activity events mirroring audio (`EventType` additions: started / completed / skipped / failed).
5. **Dispatch** — in `ProcessAssetJob`, after the main chain dispatch block (next to audio web playback): `if ($isVideo && config('assets.video.web_playback.enabled')) { GenerateVideoWebPlaybackJob::dispatch(...); }`.
6. **Delivery** — extend `AssetDeliveryService` / `deliveryUrl()` for `VIDEO_WEB`.
7. **API** — `jsonVideoStreamUrl()`: if `metadata.video.web_playback_path` set (or column if you add one), return signed **VIDEO_WEB** URL; else keep current **ORIGINAL** → **VIDEO_PREVIEW** fallback order. Document that **VIDEO_PREVIEW remains hover-only**.
8. **Frontend** — minimal change if the drawer already hits the JSON view URL for playback; verify **MIME** in response headers for the derivative is `video/mp4`.
9. **Regeneration** — optional admin endpoint “Regenerate web playback” (parallel to `regenerateVideoPreview`).
10. **Tests** — unit tests for decision matrix (no FFmpeg), optional integration test behind `RUN_FFMPEG_INTEGRATION=1`.
11. **Docs** — update `MEDIA_PIPELINE.md`, `UPLOAD_AND_QUEUE.md`, `PRODUCTION_WORKER_SOFTWARE.md` (FFmpeg build flags if any), Horizon supervisor list for `video-heavy`.

---

## Observability and UX

- **Activity / timeline:** same style as `asset.audio_web_playback.*` events so help desk can see “queued on `video-heavy`”, “completed”, “failed: ffmpeg_failed”.
- **Processing tray / drawer:** optional `metadata.video.web_playback_status` (`pending` | `ready` | `skipped` | `failed`) for a one-line message (“Preparing browser playback…”).
- **Failures:** non-fatal to asset completion; user can still **download original**; optional inline message when derivative missing and original MIME is in “risky” list.

---

## Risks and mitigations

| Risk | Mitigation |
|------|------------|
| Storage doubling | Cap resolution/bitrate; optional tenant feature flag; lifecycle cleanup when asset deleted (same as other previews) |
| CPU / queue backlog | Dedicated `video-heavy` concurrency; `max_jobs` / `max-time` in Horizon; back-pressure via dispatch delay or size limits |
| Legal / DRM | Document that FFmpeg cannot play encrypted sources; fail gracefully |
| Wrong codec detection | Start with conservative extension list; expand with ffprobe |

---

## Rollout phases (suggested)

1. **P0:** Config + decision service + job shell (skip FFmpeg, log “would transcode”) behind `config('assets.video.web_playback.enabled', false)` default **false** in production until workers verified.
2. **P1:** FFmpeg transcode for **force_extensions** only, always `video-heavy`, wire `jsonVideoStreamUrl` preference.
3. **P2:** ffprobe remux fast path + smarter MOV/WebM handling.
4. **P3:** Admin regen, metrics, and UI polish.

---

## Related code (starting points)

- `App\Jobs\ProcessAssetJob` — parallel `GenerateAudioWebPlaybackJob` dispatch pattern.
- `App\Jobs\GenerateVideoPreviewJob` — FFmpeg + S3 verify + **non-fatal** failure handling.
- `App\Services\VideoPreviewGenerationService` — download, FFmpeg path discovery, orientation handling (`VideoDisplayProbe`).
- `App\Http\Controllers\AssetController::jsonVideoStreamUrl()` — playback URL selection.
- `App\Support\AssetVariant` / `AssetVariantPathResolver` / `AssetDeliveryService` — new variant wiring.
- `config/queue.php` — `video_heavy_queue`; `jackpot/compose.yaml` — `queue_video_heavy` worker.

This document is the **product + engineering plan**; implement in small PRs following the checklist above.
