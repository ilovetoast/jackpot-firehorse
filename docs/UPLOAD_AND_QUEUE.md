# Upload, queue, and pipeline

Operational guide: workers, upload dispatch, diagnostic logging, sequencing, and deploy behavior.

---


## Overview

Queue workers process background jobs (thumbnail generation, metadata extraction, etc.). **Queue workers must be running for thumbnail processing to work.**

**Horizon (Redis):** per-pool process counts and kill-switch are in `config/horizon.php` (env keys `HORIZON_*_PROCESSES` and `QUEUE_WORKERS_ENABLED`). Staging uses conservative defaults; set heavy pools to `0` on small instances. For overload / stuck queues, see [Horizon emergency runbook](operations/HORIZON_EMERGENCY_RUNBOOK.md).

### Local Sail: `ai` queue (upload AI tagging & metadata)

**The default Sail `queue` service must consume the `ai` queue** or upload-time AI jobs (`AiMetadataGenerationJob` → `AiTagAutoApplyJob` → `AiMetadataSuggestionJob`, dispatched to `config('queue.ai_queue', 'ai')`) will sit in `jobs` forever while thumbnails still process on `images*`.

- **Staging/production:** Use Horizon; `supervisor-ai` already listens on `ai` and `ai-low` (see `config/horizon.php`). No change required for deployed environments.
- **Local Sail:** `compose.yaml` runs `queue:work database` with `--queue=default,images,images-heavy,pdf-processing,ai,ai-low` (see that file for the exact flags).

### Local / staging / production: Blender for DAM 3D (optional)

Real 3D poster renders use **Blender 4.5.3 LTS** from the **official tarball**, symlinked as **`/usr/local/bin/blender`**, with **`DAM_3D_BLENDER_BINARY=/usr/local/bin/blender`** on **workers only** (the same hosts that run `images` / `images-heavy` thumbnail jobs — not web-only PHP). **Do not** use `apt install blender` for this pipeline (often ships **3.0.x** on Ubuntu). Install and verification steps: [environments/BLENDER_DAM_3D_INSTALL.md](environments/BLENDER_DAM_3D_INSTALL.md).

**Manual worker (debug / one-off):**

```bash
./vendor/bin/sail artisan queue:work database --queue=default,images,images-heavy,pdf-processing,ai,ai-low --tries=3 --timeout=960 -vvv
```

**If you run Redis + Horizon locally instead of the database worker:**

```bash
./vendor/bin/sail artisan horizon
```

## Quick Start

### Start Queue Worker (Docker Compose)

The queue worker runs as a dedicated Docker service:

```bash
# Start all services (including queue worker)
./vendor/bin/sail up -d

# Start only the queue worker
./vendor/bin/sail up -d queue

# Verify it's running
./vendor/bin/sail ps | grep queue
```

### Verify Workers Are Running

```bash
# Check Docker services
./vendor/bin/sail ps | grep queue

# Check for stuck jobs
./vendor/bin/sail artisan queue:health-check

# View worker logs
./vendor/bin/sail logs -f queue
```

## Configuration

**Queue Connection:** `database` (configured in `config/queue.php`)

**Worker Settings** (in `compose.yaml` `queue` service):
- `--queue=default,images,images-heavy,pdf-processing,ai,ai-low` — includes **`ai`** so local uploads run AI metadata/tagging (see section above)
- `--tries=3` - Maximum retry attempts per job
- `--timeout=960` - Long enough for heavy thumbnails, PDF processing, and AI vision jobs
- `--sleep=3` - Seconds to sleep when no jobs available
- `--max-jobs=1000` - Restart worker after N jobs (prevents memory leaks)
- `--max-time=3600` - Restart worker after 1 hour (prevents memory leaks)

**Auto-restart:** `restart: unless-stopped` - Worker restarts automatically if it crashes

## Common Commands

### Start/Stop

```bash
# Start queue worker
./vendor/bin/sail up -d queue

# Stop queue worker
./vendor/bin/sail stop queue

# Restart queue worker
./vendor/bin/sail restart queue
```

### Manual Job Processing

```bash
# Process one job (for testing/debugging)
./vendor/bin/sail artisan queue:work --once

# Process one job with specific retry count
./vendor/bin/sail artisan queue:work --once --tries=1

# Full local lane (matches Sail `queue` service — use for verifying AI + pipeline)
./vendor/bin/sail artisan queue:work database --queue=default,images,images-heavy,pdf-processing,ai,ai-low --tries=3 --timeout=960 -vvv
```

### Database driver: pending counts by queue

When `QUEUE_CONNECTION=database`, MySQL holds queued payloads in the `jobs` table:

```sql
SELECT queue, COUNT(*) AS c FROM jobs GROUP BY queue ORDER BY c DESC;
```

### Health Check

```bash
# Check for stuck jobs
./vendor/bin/sail artisan queue:health-check

# Check with custom stale threshold (10 minutes)
./vendor/bin/sail artisan queue:health-check --stale-minutes=10

# Warning only (doesn't exit with error)
./vendor/bin/sail artisan queue:health-check --warn-only
```

### Retry failed jobs

```bash
./vendor/bin/sail artisan queue:retry all
```

### View Logs

```bash
# Follow queue worker logs
./vendor/bin/sail logs -f queue

# View last 100 lines
./vendor/bin/sail logs --tail=100 queue
```

## Troubleshooting

### Problem: Thumbnails Never Complete

**Symptoms:**
- AssetProcessingTray shows "Processing (1)" forever
- Thumbnails never appear
- Jobs stuck in queue

**Solution:**

1. **Check if worker is running:**
   ```bash
   ./vendor/bin/sail ps | grep queue
   ```
   If no queue service appears, start it:
   ```bash
   ./vendor/bin/sail up -d queue
   ```

2. **Check for stuck jobs:**
   ```bash
   ./vendor/bin/sail artisan queue:health-check
   ```

3. **If jobs are stuck, manually process them:**
   ```bash
   # Process stuck jobs one at a time
   ./vendor/bin/sail artisan queue:work --once --tries=1
   # Repeat until queue is empty
   ```

4. **Restart the worker:**
   ```bash
   ./vendor/bin/sail restart queue
   ```

### Problem: Worker Keeps Crashing

**Check logs:**
```bash
./vendor/bin/sail logs queue
```

**Common causes:**
- Database connection errors → Ensure MySQL is running
- Memory limits → Check PHP memory_limit in Dockerfile
- Timeout errors → Jobs exceeding timeout (thumbnail jobs: 600s; default: 300s)

### Problem: Jobs Process But Thumbnails Don't Appear

This is a different issue (likely frontend or file storage). Check:
1. S3/storage configuration
2. Thumbnail file paths
3. Frontend polling (if enabled)

## Development Workflow

### Starting Development Session

1. Start Sail services:
   ```bash
   ./vendor/bin/sail up -d
   ```

2. Verify queue worker is running:
   ```bash
   ./vendor/bin/sail ps | grep queue
   ```

3. Check queue health:
   ```bash
   ./vendor/bin/sail artisan queue:health-check
   ```

### During Development

- Queue worker runs continuously in background
- Jobs process automatically
- View logs: `./vendor/bin/sail logs -f queue`
- Health check: `./vendor/bin/sail artisan queue:health-check`

### Stopping Development Session

```bash
# Stop all services (including queue worker)
./vendor/bin/sail down

# Stop only queue worker (keep other services running)
./vendor/bin/sail stop queue
```

## Download ZIP Jobs (Phase D-3)

By default, `BuildDownloadZipJob` runs on the **default** queue so a single `php artisan queue:work` processes download ZIP builds. If downloads stay "Preparing" forever, ensure a queue worker is running (and not only a worker that listens to a different queue).

To use a dedicated queue for download jobs (e.g. for higher memory/timeout), set in `.env`:

```env
QUEUE_DOWNLOADS_QUEUE=downloads
```

Then run a separate worker for the downloads queue with:

- **memory** >= 2048MB
- **concurrency** <= 2
- **timeout** >= 900s (15 min)

Example (Supervisor or similar):

```bash
php artisan queue:work database --queue=downloads --timeout=900 --memory=2048 --tries=3
```

For Docker/Sail, define a dedicated `queue-downloads` service with these flags. (No auto-deploy logic; configure manually.)

## Production Notes

⚠️ **This setup is for local development only.**

For production:
- Use Supervisor or systemd to manage queue workers
- Configure proper logging and monitoring
- Set up queue worker health checks
- Use Redis or SQS for better performance
- Configure multiple workers for high throughput

## Related Files

- `compose.yaml` - Queue worker service definition
- `config/queue.php` - Queue connection configuration
- `app/Console/Commands/QueueHealthCheck.php` - Health check command
- `storage/logs/laravel.log` - Application logs (includes job logs)


---

## Queue dispatch and upload flow


## Pipeline (no env guards)

1. **UploadController** (finalize) → `UploadCompletionService::complete()`
2. **UploadCompletionService::complete()** (inside `DB::transaction`) → `event(new AssetUploaded($asset))` (after commit via `DB::afterCommit`)
3. **ProcessAssetOnUpload** (listener, `ShouldQueue`) → queued to default connection (Redis in staging)
4. Worker runs **ProcessAssetOnUpload::handle()** → `ProcessAssetJob::dispatch($asset->id)`
5. **ProcessAssetJob** dispatches **two parallel chains** (no env conditionals):
   - **Main chain** on the images / images-heavy / images-psd queue (resolved by `PipelineQueueResolver` from byte size + MIME):
     `GenerateThumbnailsJob → GeneratePreviewJob → [GenerateVideoPreviewJob if video and hover not deferred for VIDEO_WEB] → ExtractMetadataJob → ExtractEmbeddedMetadataJob → EmbeddedUsageRightsSuggestionJob → ComputedMetadataJob → PopulateAutomaticMetadataJob → ResolveMetadataCandidatesJob → FinalizeAssetJob → PromoteAssetJob`

     When **`VIDEO_WEB_PLAYBACK_ENABLED`** is true, **`GenerateVideoWebPlaybackJob`** is also dispatched **beside** this chain onto **`config('queue.video_heavy_queue', 'video-heavy')`** (same pattern as **`GenerateAudioWebPlaybackJob`**): full-length H.264/AAC MP4 for “risky” containers (see `config('assets.video.web_playback.force_extensions')` and [VIDEO_WEB_PLAYBACK_PLAN.md](VIDEO_WEB_PLAYBACK_PLAN.md)). For those containers, **`GenerateVideoPreviewJob`** is **not** in the main chain; **`metadata.video.preview_deferred_for_web_playback`** is set until **`GenerateVideoWebPlaybackJob`** finishes and dispatches hover preview from the **VIDEO_WEB** MP4 on the **images** queue. Safe/native videos still run **`GenerateVideoPreviewJob`** in-chain from the **original**. **`GenerateVideoPreviewJob`** stays **hover-only**.
   - **AI follow-up chain** on `config('queue.ai_queue', 'ai')` (Horizon `supervisor-ai` in staging/production; **Sail `queue` service must include `ai` locally**), only when tenant policy + upload-time skip flags allow:
     `AiMetadataGenerationJob → [AiTagAutoApplyJob if not _skip_ai_tagging] → [AiMetadataSuggestionJob if not _skip_ai_metadata]`

   **`AITaggingJob` is deprecated:** it remains in the codebase as a **no-op** so old queued payloads do not crash. Real tag/candidate work runs in **`AiMetadataGenerationJob`** on the `ai` queue. Do not add new dispatches of `AITaggingJob`.

The two chains run on different supervisors so AI vision calls cannot starve thumbnail/preview workers. AI jobs already poll/guard on `thumbnail_status === COMPLETED` before doing real vision work, so running them in parallel with the tail of the main chain is safe.

### Time-to-first-thumbnail fast path

`GenerateThumbnailsJob` is the **first** job in the main chain so users see standard/original thumbnails as quickly as possible.

`ProcessAssetJob` first classifies the file against `AssetProcessingBudgetService` (worker profile from `config/asset_processing.php`). If the asset is too large for this worker, it short-circuits or re-dispatches to a heavy pipeline queue (see `ASSET_DEFER_HEAVY_TO_QUEUE` in `config/asset_processing.php`) **before** `FileInspectionService::inspect()` runs, so huge PSDs never hit Imagick on small profiles. Otherwise it runs `FileInspectionService::inspect()` synchronously before dispatching the chain, which writes `width`, `height`, and `mime_type` onto the asset row and current version. That means thumbnails do **not** depend on `ExtractMetadataJob`:

Guardrail observability: search logs for `[asset_processing_guardrail]` (see `docs/MEDIA_PIPELINE.md` § Worker profiles).

- Raster images: dimensions are already on the row when `GenerateThumbnailsJob` starts. EXIF orientation is handled inside `ThumbnailGenerationService` while decoding.
- PDF / SVG / PSD / PSB / video: `GenerateThumbnailsJob` derives dimensions from the renderer / Imagick / FFprobe at decode time (`$dimensionsFromRendering` branch).

Full `ExtractMetadataJob` / `ExtractEmbeddedMetadataJob` / `EmbeddedUsageRightsSuggestionJob` work runs **after** thumbnails as enrichment.

### Preferred thumbnails (disabled)

`GeneratePreferredThumbnailJob` is gated by `config('assets.thumbnail.preferred.enabled', false)` (env: `THUMBNAIL_PREFERRED_ENABLED`, default `false`). While disabled it is **not** dispatched anywhere — including from `GenerateThumbnailsJob`'s post-success hook. UI grid falls back to the original thumbnail in this state.

### `[asset_pipeline_timing]` log key

`App\Support\Logging\AssetPipelineTimingLogger` emits one `Log::info('[asset_pipeline_timing]', …)` row at each major transition. Always-on, no signed URLs / S3 keys / filenames are logged. Each row carries `event`, `asset_id`, `asset_version_id`, `ts`, optional `ms_since_processing_marked` (wall time since `processing_started_at` was written), and small non-sensitive context (queue name, status, counts).

| `event` | Emitted from | When |
|---|---|---|
| `original_stored` | `ProcessAssetJob` | Right after `processing_started_at` is written. Baseline for ms-since markers. |
| `thumbnail_dispatched` | `ProcessAssetJob` | Immediately after `Bus::chain(...)->dispatch()` for the main chain. |
| `ai_chain_dispatched` | `ProcessAssetJob` | Immediately after the AI chain is dispatched (only when AI jobs survive policy + skip flags). |
| `thumbnail_started` | `GenerateThumbnailsJob` | At the start of `handle()` after asset/version resolution. |
| `thumbnail_completed` | `GenerateThumbnailsJob` | On the success path after thumbnails are written. |
| `preview_completed` | `GeneratePreviewJob` | After preview marker is written. |
| `metadata_completed` | `ExtractMetadataJob` | After metadata payload is written. |

Grep helper:

```bash
./vendor/bin/sail exec laravel.test grep "\[asset_pipeline_timing\]" storage/logs/laravel.log
```

The granular per-step timer (`[pipeline_timing]`, `App\Support\Logging\PipelineStepTimer`) remains gated behind `ASSET_PIPELINE_LOG_STEP_TIMINGS=true` for diagnostic deep-dives.

## Dispatch points (upload path)

| Site | Job / event | Env conditionals | Reaches staging? |
|------|-------------|------------------|------------------|
| UploadCompletionService::complete() | `event(AssetUploaded)` → queues ProcessAssetOnUpload | None | Yes (after commit) |
| ProcessAssetOnUpload::handle() | `ProcessAssetJob::dispatch()` | None | Yes (when listener runs) |
| ProcessAssetJob | Main `Bus::chain(…)` on `images*` queue | None | Yes |
| ProcessAssetJob | AI `Bus::chain(…)` on `ai` queue | Tenant AI policy + `_skip_ai_*` flags | Yes when allowed |

## AI queue routing

All AI vision/suggestion dispatch sites target `config('queue.ai_queue', 'ai')` so they cannot land on the images queue:

- `ProcessAssetJob` — post-upload AI follow-up chain
- `AssetController::regenerateAiMetadata` — manual AI rerun
- `AssetMetadataController` — `AiMetadataSuggestionJob::dispatch`
- `BulkActionService` — bulk AI rerun
- `StagedFiledAssetAiService` — studio/staged-filed follow-up
- `IngestPdfPagesForAiJob` — PDF AI ingestion chain

Horizon already exposes `supervisor-ai` listening on `[ai, ai-low]` (see `config/horizon.php`). Scale via `HORIZON_AI_PROCESSES` if the AI queue backlogs. **Staging/production behavior is unchanged** — this document’s Sail worker notes apply to **local** `compose.yaml` only.

## Notes

- No `app()->environment('production')` or `app()->isLocal()` around upload-related dispatch.
- Event is fired inside `DB::transaction`; deferred to **after commit** so the queued listener runs only when the asset is committed (avoids worker racing transaction in Redis/staging).
- Local: `QUEUE_CONNECTION=sync` → listener and ProcessAssetJob run synchronously in the same request; `DB::afterCommit` still fires after commit, behavior unchanged.
- Queue: listener and ProcessAssetJob use default queue; worker must run `queue:work` (or Horizon) on default.


---

## Upload diagnostic logging


**Temporary** diagnostic logging for debugging upload pipeline issues. Remove after tests complete.

## How to Use

1. Upload your 6 assets (different file types).
2. After uploads complete, grep the Laravel log:

```bash
# From Laravel root (jackpot/)
grep "UPLOAD_DIAG" storage/logs/laravel.log
```

Or with Sail:

```bash
./vendor/bin/sail exec laravel.test grep "UPLOAD_DIAG" storage/logs/laravel.log
```

## What Gets Logged

Each log line is prefixed with `[UPLOAD_DIAG]` and includes:

- **asset_id** – Asset ID
- **filename** – Original filename
- **status** – Asset visibility (visible, hidden, failed)
- **thumbnail_status** – pending, processing, completed, failed, skipped
- **analysis_status** – uploading, generating_thumbnails, extracting_metadata, etc.
- **published_at** – When published (null = unpublished, may not appear in default grid)
- **approval_status** – not_required, pending, approved, rejected
- **category_id** – Category for the asset
- **metadata_extracted** – Whether ExtractMetadataJob completed
- **thumbnails_generated** – Whether thumbnails were generated
- **preview_generated** – Whether preview was generated
- **preview_skipped** / **preview_skipped_reason** – If preview was skipped (e.g. thumbnails_not_completed)
- **version_id** – Asset version ID (version-aware pipeline)
- **version_pipeline_status** – pending, processing, complete, failed
- **thumbnail_styles** – Which thumbnail styles exist (thumb, medium, large, preview)

## Pipeline Flow (Logged Points)

1. **UploadCompletionService ASSET_CREATED** – Asset and version created
2. **ProcessAssetOnUpload DISPATCHING** – Listener dispatching ProcessAssetJob
3. **ProcessAssetJob START** – Chain about to run
4. **ExtractMetadataJob** – START / COMPLETE / SKIP
5. **GenerateThumbnailsJob** – START / COMPLETE / SKIP / FAIL
6. **GeneratePreviewJob** – START / COMPLETE / SKIP
7. **GenerateVideoPreviewJob** – START / COMPLETE / SKIP (video only)
8. **ComputedMetadataJob** – START / COMPLETE / SKIP
9. **PopulateAutomaticMetadataJob** – START / COMPLETE
10. **ResolveMetadataCandidatesJob** – START / COMPLETE / SKIP
11. **FinalizeAssetJob** – START / COMPLETE / SKIP
12. **PromoteAssetJob** – START / COMPLETE / SKIP

## Diagnosing Common Issues

| Issue | What to check in logs |
|-------|------------------------|
| **No previews** | `GeneratePreviewJob SKIP` with `thumbnails_not_completed` → thumbnails failed or skipped |
| **No metadata** | `ExtractMetadataJob` / `PopulateAutomaticMetadataJob` SKIP or missing COMPLETE |
| **Disappearing from grid** | `status`=hidden, `published_at`=null, or `approval_status`=pending |
| **Wrong versioning** | `version_id` and `version_pipeline_status` at each step |
| **Thumbnail failures** | `GenerateThumbnailsJob FAIL` or SKIP with reason |

## Removing After Tests

Search for `UploadDiagnosticLogger` and `UPLOAD_DIAG` and remove all usages. Delete `app/Services/UploadDiagnosticLogger.php` and this doc.


---

## Pipeline sequencing


**Last Updated**: 2026-01-28  
**Status**: Active Documentation

This document defines the sequencing guarantees for the asset processing pipeline, specifically the relationship between thumbnail generation and image-derived jobs.

---

## Canonical Invariant

**NO image-derived job may run until**:
- `thumbnail_status === ThumbnailStatus::COMPLETED`
- OR a source image is confirmed readable (future enhancement)

This invariant prevents:
- Dominant color extraction failures (needs image access)
- AI image analysis failures (needs image access)
- Metadata derivation failures (needs image access)

---

## Thumbnail Readiness Signal

**Canonical Signal**: `thumbnail_status === ThumbnailStatus::COMPLETED`

- Set by `GenerateThumbnailsJob` on successful completion
- Checked by all image-derived jobs before processing
- Enforced via explicit gates in job handlers

**Alternative Signals** (not currently used):
- `thumbnails_ready_at` timestamp (future enhancement)
- `has_thumbnails` boolean flag (future enhancement)
- Derived file existence check (future enhancement)

---

## Two Models for Handling Thumbnail Dependencies

### Option A — "Retry Until Ready" (Current Direction)

**Best when**:
- Thumbnails are guaranteed to complete eventually
- You want work to resume automatically
- The job chain should be self-healing

**Implementation**:
- Job checks `thumbnail_status` at start
- If not ready, calls `release(delay)` to reschedule
- Job retries automatically until thumbnails are ready
- No manual intervention required

**Current Usage**:
- `PopulateAutomaticMetadataJob` — releases and retries

**Example**:
```php
if ($asset->thumbnail_status !== ThumbnailStatus::COMPLETED) {
    $this->release(60); // Retry in 60 seconds
    return;
}
```

### Option B — "Skip + Re-trigger on Completion"

**Best when**:
- Thumbnail job explicitly dispatches downstream work
- You want explicit orchestration
- Downstream jobs should never retry themselves

**Implementation**:
- Job checks `thumbnail_status` at start
- If not ready, marks as skipped and exits
- `GenerateThumbnailsJob` dispatches downstream jobs on completion
- Thumbnail job is the orchestrator

**Historical note (deprecated)**:
- `AITaggingJob` — **legacy no-op**; kept so old serialized jobs still dequeue safely. Upload AI flow uses `AiMetadataGenerationJob` / `AiTagAutoApplyJob` / `AiMetadataSuggestionJob` on the `ai` queue instead.

**Example**:
```php
if ($asset->thumbnail_status !== ThumbnailStatus::COMPLETED) {
    $this->markAsSkipped($asset, 'thumbnail_unavailable');
    return;
}
```

---

## Current state (upload AI vs legacy job)

- **`AITaggingJob`:** Deprecated **no-op** (see `app/Jobs/AITaggingJob.php`). Not part of the live `ProcessAssetJob` chain.
- **Upload AI tagging / metadata:** `AiMetadataGenerationJob` → `AiTagAutoApplyJob` → `AiMetadataSuggestionJob` on `config('queue.ai_queue', 'ai')`.
- **`PopulateAutomaticMetadataJob`:** Option A (retry with `release()` until thumbnails/metadata prerequisites are ready).

---

## Why This Issue Surfaced Now

**Context**: Pipeline sequencing regression discovered during system maturity phase.

**Root Cause**:
- Thumbnail readiness was **assumed**, not **enforced**
- Jobs ran before thumbnails were ready, causing failures

**Why It Broke Now**:
1. **Pipeline became observable** — Logging exposed the sequencing issue
2. **Lifecycle unified** — Jobs and lifecycle logic consolidated
3. **Accidental sequencing removed** — Hidden dependencies surfaced

**This is Normal System Evolution**:
- Hidden assumptions surface as systems mature
- Sequencing bugs appear when dependencies become explicit
- Fixing them makes the system stronger than before

**This is NOT instability** — it's a sign of crossing from "it works" to "it's correct".

---

## Enforcement

### Jobs with thumbnail gates

1. **`AITaggingJob` (deprecated)**
   - No-op in `handle()`; do not use for new work.

2. **`AiMetadataGenerationJob` / `AiTagAutoApplyJob` / `AiMetadataSuggestionJob`**
   - Run on **`config('queue.ai_queue', 'ai')`** (ensure local Sail worker consumes `ai`).
   - `AiMetadataGenerationJob` waits/polls for a resolvable thumbnail path (parallel with the tail of the main chain), up to `assets.processing.ai_metadata_thumbnail_max_wait_seconds` (default 540s), then may skip or self-heal if thumbnails arrive late.

3. **PopulateAutomaticMetadataJob**
   - Model: Option A (retry)
   - Gate: `thumbnail_status !== COMPLETED` → release(60)
   - Status: Enforced ✅

### Tests

**`PipelineSequencingTest`** verifies:
- Jobs skip/release when thumbnails not ready
- Jobs run normally when thumbnails are ready
- Gates exist in code (code inspection)

---

## Related Documentation

- `/docs/ASSET_LIFECYCLE_CONTRACTS.md` — Lifecycle contracts
- `/docs/MEDIA_PIPELINE.md` — Thumbnail generation details
- `/tests/Feature/PipelineSequencingTest.php` — Sequencing tests

---

## Change Log

- **2026-01-28**: Initial sequencing documentation
  - Defined canonical invariant
  - Documented two models (Option A vs Option B)
  - Explained why issue surfaced now
  - Added enforcement gates to `PopulateAutomaticMetadataJob`


---

## Deploy interruption behavior


When Horizon is terminated during deployment, workers restart cleanly. This document describes expected behavior.

## Deploy Hook Sequence

1. `php artisan queue:restart` — Signals workers to finish current job, then exit.
2. `php artisan horizon:terminate` — Gracefully stops Horizon; workers drain and restart under Supervisor.

## Chain Interruption

- Jobs in `Bus::chain()` (e.g. ProcessAssetJob → ExtractMetadataJob → GenerateThumbnailsJob → …) may be interrupted mid-chain when workers are terminated.
- Interrupted jobs are recorded in:
  - `failed_jobs` (Laravel)
  - `system_incidents` (Unified Operations layer)
- Admin can see true system state via **Operations Center** (`/app/admin/operations-center`).
- Client-facing Asset Details shows "Processing Issue Detected" when unresolved incident exists, with **Retry Processing** and **Submit Support Ticket** actions.

## Expected Outcome

- Deploy interruptions are no longer silent.
- Stuck assets are detected within 5 minutes by `assets:watchdog`.
- Timeout failures are recorded properly via JobFailed listener and GenerateThumbnailsJob catch.
- Scheduler health visible correctly (Redis heartbeat).
- Workers restart cleanly after deploy.


---

# Merged phase reference: upload, observability, download

The following sections preserve the former standalone `PHASE_*.md` documents in full.


## Source: PHASE_2_UPLOAD_SYSTEM.md


**Status: ✅ Production Ready and Locked**

**Last Updated:** January 2025

---

## Overview

Phase 2 delivers a production-ready, enterprise-grade file upload system for the Digital Asset Management (DAM) platform. This phase establishes the core upload infrastructure, supporting both direct and multipart uploads with full resume, recovery, and lifecycle management capabilities.

**⚠️ Phase 2 is LOCKED.** No new features or refactors should be added without starting Phase 3.

---

## Production-Ready Capabilities

### 1. UploadSession Model & Lifecycle

- **UploadSession** model tracks upload state from initiation through completion
- Guarded status transitions (INITIATING → UPLOADING → COMPLETED/FAILED/CANCELLED)
- Terminal state detection prevents invalid operations
- Expiration handling with automatic cleanup
- Tenant and brand isolation enforced at model level

### 2. Multipart & Direct Upload Support

- **Direct uploads** for small files (< 5MB) via pre-signed S3 URLs
- **Multipart uploads** for large files (> 5MB) with chunked transfer
- Automatic strategy selection based on file size
- S3 integration with presigned URLs for secure, direct-to-S3 uploads
- Immutable temporary upload path contract: `temp/uploads/{upload_session_id}/original`

### 3. Resume & Recovery Logic

- **Resume metadata endpoint** (`GET /app/uploads/{uploadSession}/resume`) provides:
  - Current upload status
  - Already uploaded parts (for multipart)
  - Multipart upload ID
  - Chunk size and expiration info
  - `can_resume` boolean flag

- **Frontend UploadManager** (singleton) supports:
  - Refresh-safe state recovery from localStorage
  - Automatic resume on page reload
  - Per-part progress tracking for multipart uploads
  - Parallel upload orchestration

- **Abandoned session detection** via scheduled command:
  - Identifies UPLOADING sessions without recent activity
  - Marks expired/abandoned sessions as FAILED
  - Prevents resource leaks

### 4. Cleanup & Lifecycle Handling

- **Scheduled cleanup jobs** run every 1-6 hours (configurable):
  - Expired upload session cleanup
  - Orphaned temporary S3 object removal
  - Abandoned multipart upload abortion

- **Best-effort, non-blocking cleanup**:
  - Never throws on storage failures
  - Logs all cleanup attempts
  - Emits audit events for observability

- **Orphaned multipart upload detection**:
  - Scans S3 for multipart uploads without matching UploadSession
  - Aborts uploads older than safe threshold (24h default)
  - Pagination-aware S3 scanning

### 5. Presigned URL Support

- **Multipart part URLs** (`POST /app/uploads/{uploadSession}/multipart-part-url`):
  - Secure, time-limited presigned URLs for individual parts
  - 15-minute expiration
  - Validates UploadSession state before generation
  - Idempotent and side-effect free

- **Direct upload URLs** provided during batch initiation
- AWS S3 `UploadPart` operation with proper IAM permissions

### 6. Permission & Tenant Isolation

- All endpoints enforce tenant ownership
- Upload sessions scoped to tenant and brand
- IAM policies restrict access to tenant-specific S3 paths
- Permission gates on upload endpoints (to be extended in Phase 3)

### 7. Batch Upload Support

- **Batch initiation endpoint** (`POST /app/uploads/initiate-batch`):
  - Supports multiple files in single request
  - Individual transaction isolation per file
  - Optional `batch_reference` for frontend correlation
  - `client_reference` mapping for frontend state management

- Transaction isolation ensures one file failure doesn't affect others

### 8. Idempotent Operations

- **Completion endpoint** (`POST /app/assets/upload/complete`):
  - Prevents duplicate asset creation
  - Safe to retry on network errors
  - Returns existing asset if already completed

- **Cancellation endpoint** (`POST /app/uploads/{id}/cancel`):
  - Safe to call multiple times
  - Returns current state if already terminal

---

## Architecture Components

### Backend Services

- **UploadInitiationService**: Handles upload session creation, presigned URL generation
- **UploadCompletionService**: Validates uploads, creates Asset records
- **ResumeMetadataService**: Queries S3 for resume state, updates activity timestamps
- **AbandonedSessionService**: Detects and marks abandoned uploads
- **UploadCleanupService**: Cleans expired/terminal upload sessions
- **MultipartCleanupService**: Aborts orphaned S3 multipart uploads
- **MultipartUploadUrlService**: Generates presigned URLs for multipart parts

### Scheduled Commands

- **DetectAbandonedUploadSessions**: Runs periodically to mark abandoned uploads as FAILED
- **CleanupExpiredUploadSessions**: Runs every 1-6 hours for cleanup

### Frontend Components

- **UploadManager** (singleton): Core upload orchestration, state management, resume logic
- **UploadAssetDialog** (temporary): Minimal UI harness for Phase 2 verification
- **AddAssetButton**: Entry point for upload dialog

---

## API Endpoints

### Upload Management

- `POST /app/uploads/initiate` - Single file upload initiation
- `POST /app/uploads/initiate-batch` - Batch upload initiation
- `POST /app/assets/upload/complete` - Complete upload and create asset
- `POST /app/uploads/{id}/cancel` - Cancel upload session (idempotent)
- `GET /app/uploads/{id}/resume` - Get resume metadata
- `PUT /app/uploads/{id}/activity` - Update activity timestamp
- `POST /app/uploads/{uploadSession}/multipart-part-url` - Get presigned URL for multipart part

---

## Out of Scope (Not Included in Phase 2)

The following features are **explicitly deferred** to future phases:

### UX & Interface
- ❌ Final uploader UX/UI (current dialog is temporary verification harness)
- ❌ Drag-and-drop polish
- ❌ Progress animations
- ❌ Thumbnail previews
- ❌ File reordering
- ❌ Batch edit flows
- ❌ Keyboard shortcuts
- ❌ Accessibility polish

### Advanced Features
- ❌ Asset metadata editing during upload
- ❌ Collections/albums creation
- ❌ Direct asset-to-category assignment (category validated in UI but not yet passed to backend)
- ❌ Advanced retry UI (backend supports retry, but no UI controls)
- ❌ Pause/resume UI controls (resume is automatic on refresh)

### Infrastructure
- ❌ CDN integration
- ❌ Alternative storage backends
- ❌ Asset versioning
- ❌ Duplicate detection

---

## Validation & Testing

- ✅ Real AWS S3 integration tests (tagged with `@group aws`)
- ✅ Multipart upload flow validated with IAM Policy Simulator
- ✅ Transaction isolation verified
- ✅ Idempotency tested
- ✅ Resume flow validated
- ✅ Cleanup jobs tested
- ✅ Temporary UI harness validates end-to-end flow

**Note:** Automated Laravel integration tests requiring a dedicated test database are deferred until `.env.testing` and isolated test DB are configured.

---

## Migration Path

Phase 2 is **locked**. Future enhancements must be:

1. **Planned as Phase 3** (or later)
2. **Not modify existing Phase 2 services** without explicit approval
3. **Maintain backward compatibility** with existing upload sessions

### Safe Areas for Future Development

- New UI components (can replace temporary dialog)
- New endpoints (as long as they don't modify existing behavior)
- Additional validation rules (additive only)
- Enhanced logging/observability (additive only)

### Off-Limits Without Phase 3 Approval

- Modifying UploadSession status transition logic
- Changing temporary upload path contract
- Altering UploadCompletionService asset creation logic
- Removing or changing existing API endpoint signatures
- Refactoring core UploadManager orchestration

---

## Configuration

### Environment Variables

- `STORAGE_PROVISION_STRATEGY` - Storage provisioning strategy (e.g., 'shared')
- `AWS_ACCESS_KEY_ID` - AWS credentials
- `AWS_SECRET_ACCESS_KEY` - AWS credentials
- `AWS_BUCKET` - S3 bucket name

### Scheduling

Cleanup commands are registered in `app/Console/Kernel.php`:

```php
$schedule->command('uploads:detect-abandoned')->hourly();
$schedule->command('uploads:cleanup')->everySixHours();
```

---

## Related Documentation

- [Activity Logging Implementation](./ACTIVITY_LOGGING_IMPLEMENTATION.md)
- AWS IAM Policy configuration (see IAM policies in AWS console)

---

## Support & Maintenance

For issues or questions regarding Phase 2 upload system:

1. Check this documentation first
2. Review service class docblocks for implementation details
3. Verify IAM permissions match documented requirements
4. Check Laravel logs for cleanup job execution

**Phase 2 is production-ready and locked. For enhancements, start Phase 3 planning.**


## Source: PHASE_2_5_OBSERVABILITY_LOCK.md


**Status:** ✅ COMPLETE & LOCKED  
**Date Locked:** 2024  
**Next Phase:** Downloader System (not yet implemented)  
**Phase 3:** EXPLICITLY SKIPPED

---

## Lock Declaration

**Phase 2.5 is COMPLETE and IMMUTABLE.**

This phase implements upload error observability and diagnostics infrastructure. All code, contracts, and behavior in this phase are **LOCKED** and must not be refactored, renamed, or modified.

Future phases may only:
- **CONSUME** error signals emitted by Phase 2.5
- **READ** normalized error data
- **DISPLAY** error information

Future phases must **NOT**:
- Modify error normalization logic
- Change error shape contracts
- Refactor observability utilities
- Remove or weaken environment gating
- Modify diagnostics panel behavior

---

## Scope & Deliverables

Phase 2.5 implemented:

1. **Frontend Error Normalization** (Step 1)
   - Centralized error normalization utility
   - Consistent error shapes across upload flow
   - AI-ready error signals

2. **Backend Error Response Consistency** (Step 2)
   - Centralized error response helper
   - Normalized error payloads from backend
   - Consistent HTTP status code mapping

3. **Dev-Only Diagnostics Panel** (Step 3)
   - Read-only developer diagnostics UI
   - Environment-gated visibility
   - Normalized error inspection

4. **Environment Awareness** (Step 4)
   - Centralized environment detection
   - Consistent logging behavior
   - Dev-only gating utilities

5. **Retry-State Clarity** (Step 5)
   - Visual retryability indicators
   - Clear user-facing error messages
   - UI-only improvements

---

## Canonical Upload Error Contract

### Frontend Error Shape (Normalized)

```typescript
{
  category: "AUTH" | "CORS" | "NETWORK" | "VALIDATION" | "PIPELINE" | "UNKNOWN",
  error_code: string,  // Stable enum (e.g., "UPLOAD_AUTH_EXPIRED")
  message: string,     // Human-readable message
  http_status?: number,
  upload_session_id: string | null,
  asset_id: string | null,
  file_name: string,
  file_type: string,   // File extension (e.g., "pdf", "jpg")
  retryable: boolean,
  raw: {}              // Dev-only: original error payload
}
```

### Backend Error Response Shape

```json
{
  "error_code": "UPLOAD_AUTH_EXPIRED",
  "message": "Your session has expired. Please refresh and try again.",
  "category": "AUTH",
  "context": {
    "upload_session_id": "uuid",
    "asset_id": null,
    "file_type": "pdf",
    "pipeline_stage": "upload|finalize|thumbnail"
  }
}
```

### Error Categories

- **AUTH**: Authentication/authorization failures (401, 403, 419)
- **CORS**: Browser CORS/preflight blocking
- **NETWORK**: Network failures, timeouts, server errors
- **VALIDATION**: File validation errors (size, type, format)
- **PIPELINE**: Pipeline/resource conflicts (409, 410, 423, expired sessions)
- **UNKNOWN**: Unclassified errors

### Stable Error Codes

Error codes are **stable string enums** for AI pattern detection:
- `UPLOAD_AUTH_EXPIRED`
- `UPLOAD_AUTH_REQUIRED`
- `UPLOAD_PERMISSION_DENIED`
- `UPLOAD_SESSION_NOT_FOUND`
- `UPLOAD_SESSION_EXPIRED`
- `UPLOAD_PIPELINE_CONFLICT`
- `UPLOAD_FILE_TOO_LARGE`
- `UPLOAD_VALIDATION_FAILED`
- `UPLOAD_FINALIZE_VALIDATION_FAILED`
- `UPLOAD_FILE_MISSING`
- `UPLOAD_SERVER_ERROR`
- `UPLOAD_UNKNOWN_ERROR`

---

## Key Files (Locked)

### Frontend
- `resources/js/utils/uploadErrorNormalizer.js` - Error normalization utility
- `resources/js/utils/environment.js` - Environment detection utility
- `resources/js/Components/DevUploadDiagnostics.jsx` - Diagnostics panel
- `resources/js/Components/UploadItemRow.jsx` - Retry-state UI (UI-only)

### Backend
- `app/Http/Responses/UploadErrorResponse.php` - Error response helper
- `app/Http/Controllers/UploadController.php` - Uses UploadErrorResponse

---

## AI-Support Intent

Phase 2.5 establishes signals for future AI agent consumption:

### Pattern Detection Capabilities
- Group by `error_code` to detect repeated failures
- Group by `file_type` to identify file-type-specific issues
- Group by `pipeline_stage` to pinpoint failure location
- Group by `category` for high-level failure analysis
- Track `upload_session_id` for session-level correlation

### Future AI Use Cases (Not Implemented)
- "Company X had 5 failed uploads in 1 hour"
- "All PDFs are failing thumbnail generation"
- "Upload failures spike during peak hours"
- Automatic support ticket generation
- Error pattern alerts

**Note:** Phase 2.5 only emits signals. AI consumption logic is not part of this phase.

---

## Dev-Only Diagnostics Panel

### Visibility Rules
- **Environment-gated**: Only visible in development
- **Read-only**: No mutations, no actions, no buttons
- **Auto-hidden**: Returns `null` in production

### Displayed Information
- Upload session IDs
- File metadata (name, type, size)
- Upload status
- Normalized error details
- AI-support context signals

### Gating Logic
Uses `allowDiagnostics()` from `utils/environment.js`:
- Checks `window.__DEV_UPLOAD_DIAGNOSTICS__`
- Checks `process.env.NODE_ENV === 'development'`
- Checks Vite environment variables

**DO NOT** remove or weaken this gating.

---

## Explicit Non-Goals

Phase 2.5 explicitly does **NOT** include:

- ❌ Retry logic implementation
- ❌ Automatic error recovery
- ❌ Analytics aggregation
- ❌ Alert systems
- ❌ Support ticket generation
- ❌ Realtime error monitoring
- ❌ WebSocket/polling systems
- ❌ Production error exposure
- ❌ Error mutation endpoints
- ❌ Admin error management UI

These are out of scope and reserved for future phases.

---

## Phase 3 Status

**Phase 3 (Asset Interaction UX) is EXPLICITLY SKIPPED.**

The next planned phase after Phase 2.5 is the **Downloader System** phase.

Do not implement Phase 3 features or refactor Phase 3 code that may exist in the codebase.

---

## Migration & Consumption Rules

### For Future Phases

Future phases that need upload error information should:

1. **Consume normalized errors** from `item.error.normalized` (frontend)
2. **Consume error responses** matching the backend contract (API)
3. **Use error codes** for stable pattern matching
4. **Respect retryability** flags for user guidance
5. **Preserve environment gating** for diagnostics

### Anti-Patterns (DO NOT)

- ❌ Re-implementing error normalization
- ❌ Creating alternate error shapes
- ❌ Bypassing normalization utilities
- ❌ Exposing diagnostics in production
- ❌ Weakening environment checks
- ❌ Modifying error category mappings

---

## Lock Enforcement

### Code Guardrails

All Phase 2.5 files contain lock guard comments:

```
🔒 Phase 2.5 — Observability Layer (LOCKED)
This file is part of a locked phase. Do not refactor or change behavior.
Future phases may consume emitted signals only.
```

### Review Guidelines

When reviewing code changes:

1. ✅ **ALLOW**: Consuming normalized errors
2. ✅ **ALLOW**: Adding features that read error signals
3. ❌ **REJECT**: Modifying error normalization logic
4. ❌ **REJECT**: Changing error shape contracts
5. ❌ **REJECT**: Removing environment gating
6. ❌ **REJECT**: Refactoring Phase 2.5 utilities

### Breaking Change Policy

Any proposed changes to Phase 2.5 must:
- Maintain backward compatibility
- Preserve all error signals
- Keep environment gating intact
- Not modify error shapes

If breaking changes are required, they must be approved as a new phase, not a modification to Phase 2.5.

---

## Testing & Validation

Phase 2.5 behavior is validated by:

1. **Error Normalization**: All errors produce normalized shapes
2. **Backend Consistency**: All upload endpoints return consistent errors
3. **Environment Gating**: Diagnostics only visible in dev
4. **Retry Clarity**: Retryability is clearly communicated
5. **AI Signals**: Required fields present for pattern detection

**DO NOT** modify test expectations without explicit approval.

---

## History

- **Step 1**: Frontend error normalization
- **Step 2**: Backend error response consistency
- **Step 3**: Dev-only diagnostics panel
- **Step 4**: Environment awareness & logging polish
- **Step 5**: Retry-state clarity (UI only)

---

## Related Documentation

- This document, **Source: PHASE_2_UPLOAD_SYSTEM.md** section above — Phase 2 upload system (locked)
- `docs/UPLOAD_AND_QUEUE.md` - Upload pipeline, queue, diagnostics (includes former Phase 3 uploader notes)

---

**Last Updated:** 2024  
**Lock Status:** 🔒 ACTIVE  
**Next Review:** Only when Downloader System phase is planned


## Source: PHASE_3_1_DOWNLOADER_LOCK.md


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

- This document, **Source: PHASE_2_5_OBSERVABILITY_LOCK.md** section — Phase 2.5 (upload observability) lock
- Phase 2.8 Design Notice - Asset lifecycle rules for future implementation

---

**Locked By:** Phase 3.1 Implementation  
**Lock Date:** 2024  
**Next Phase:** To be determined (may include hosted press-kit pages, analytics aggregation, or download UI expansion)


## Source: PHASE_D_DOWNLOADER.md


**Status:** 📋 PLANNING  
**Baseline:** Phase 3.1 Downloader (LOCKED) — see **Source: PHASE_3_1_DOWNLOADER_LOCK.md** in this document

---

## Overview

This document defines the phased roadmap for the Jackpot downloader: foundation first (D1), then management and access (D2), advanced output (D3), and dynamic / press-kit downloads (D4). Each phase builds on the locked Phase 3.1 downloader system without modifying its locked behavior.

---

## Phase D1 — Downloader Foundation

**Scope:** Everything above (i.e. the minimal, public-only downloader built on Phase 3.1).

**In scope for D1:**
- Public download links only (no access restrictions beyond public)
- No “forever” links (expiration enforced per plan)
- No renaming of downloads
- No password protection
- Core flow: create download → build ZIP → deliver via public link → expire and cleanup

**Out of scope for D1:**
- Extend expiration (paid)
- Restrict access (brand/company/user)
- Manager controls
- Regenerate / revoke
- Naming templates, folder structures, manifests, size variants
- Non-materialized / dynamic ZIPs

**Deliverables:**
- Public download creation and delivery working end-to-end
- Expiration and cleanup aligned with Phase 3.1
- No new access modes or management features beyond what Phase 3.1 allows

---

## Phase D2 — Download Management

**Scope:** Extend expiration, restrict access, manager controls, regenerate/revoke.

**In scope for D2:**
- **Extend expiration (paid)** — Allow paid plans (or specific entitlements) to extend download link expiration beyond default.
- **Restrict access** — Restrict who can access a download by brand, company, or user (e.g. team-only, invite-only).
- **Manager controls** — UI and API for managers to create, list, and manage downloads (view, extend, restrict, revoke).
- **Regenerate / revoke** — Regenerate ZIP (e.g. after asset set changes) and revoke access (invalidate link or mark as revoked).

**Out of scope for D2:**
- Naming templates, folder structures, manifests, size variants (D3)
- Non-materialized / dynamic ZIPs (D4)

**Dependencies:** D1 complete; Phase 3.1 downloader (LOCKED) unchanged.

---

## Phase D3 — Advanced Output

**Scope:** Naming, folder structure, manifests, size variants.

**In scope for D3:**
- **Naming templates** — Configurable naming for files inside the ZIP (e.g. by metadata, date, sequence).
- **Folder structures** — Configurable folder hierarchy inside the ZIP (e.g. by collection, category, date).
- **Manifests** — Optional manifest file (e.g. CSV/JSON) listing assets and metadata included in the ZIP.
- **Size variants** — Option to include specific size variants (e.g. thumbnails, previews, originals) per asset in the download.

**Out of scope for D3:**
- Non-materialized / always-up-to-date ZIPs (D4)

**Dependencies:** D1–D2 (as needed for management of advanced options); Phase 3.1 unchanged.

---

## Phase D4 — Dynamic / Press Kit Downloads

**Scope:** Non-materialized ZIPs, always up-to-date content, premium/enterprise.

**In scope for D4:**
- **Non-materialized ZIPs** — Download built on-demand (or periodically) instead of pre-built and stored; no long-lived ZIP artifact.
- **Always up-to-date** — ZIP contents reflect current asset set (e.g. “all assets in this collection” or “current press kit”) at request time.
- **Premium / enterprise** — Feature gated to premium or enterprise plans; possible SLA/performance considerations for on-demand build.

**Out of scope for D4:**
- Changes to Phase 3.1 locked behavior (additive only)

**Dependencies:** D1–D3 as needed; Phase 3.1 unchanged.

---

## Summary Table

| Phase | Name                    | Focus                                           |
|-------|-------------------------|-------------------------------------------------|
| **D1** | Downloader Foundation   | Public links, no forever links, no renaming, no password |
| **D2** | Download Management     | Extend expiration (paid), restrict access, manager controls, regenerate/revoke |
| **D3** | Advanced Output         | Naming templates, folder structures, manifests, size variants |
| **D4** | Dynamic / Press Kit    | Non-materialized ZIPs, always up-to-date, premium/enterprise |

---

## Implementation Order

1. **D1** — Next Cursor prompt / implementation slice: deliver foundation (public only, expiration, no forever links, no renaming, no password).
2. **D2** — After D1: add management (extend expiration, restrict access, manager controls, regenerate/revoke).
3. **D3** — After D2: add advanced output (naming, folders, manifests, size variants).
4. **D4** — After D3: add dynamic / press kit downloads (non-materialized, always up-to-date, premium/enterprise).

---

## Relation to Phase 3.1

- Phase 3.1 is **LOCKED**. No changes to existing download model structure, ZIP build flow, cleanup jobs, or controller contracts.
- Phase D adds **new** behavior (access modes, management, output options, dynamic builds) in new code paths or additive configuration only.
- Where D1–D4 need to “sit on top of” 3.1, use the existing Download model, jobs, and delivery endpoints as the baseline and extend via new options, policies, or separate code paths rather than modifying locked components.

---

*For current downloader implementation details, see **Source: PHASE_3_1_DOWNLOADER_LOCK.md** in this document.*

