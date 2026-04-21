# Studio Animation 1.0 — rollout (internal)

## One output per job

- DB: unique `studio_animation_job_id` on `studio_animation_outputs`.
- Finalize always reuses the single row when fingerprint matches or when a legacy row exists (see `StudioAnimationCompletionService`).
- Full retry deletes that row and re-runs the pipeline from snapshot.

## Playwright (official renderer)

From the `jackpot/` app root:

```bash
npm ci
npx playwright install chromium
```

Enable:

- `STUDIO_ANIMATION_OFFICIAL_PLAYWRIGHT_ENABLED=true`
- `STUDIO_ANIMATION_OFFICIAL_PLAYWRIGHT_SCRIPT` = absolute path to `scripts/studio-animation/playwright-locked-frame.mjs`
- Optional: `STUDIO_ANIMATION_OFFICIAL_PLAYWRIGHT_NODE`, `STUDIO_ANIMATION_OFFICIAL_PLAYWRIGHT_TIMEOUT`

## Fallback order

`CompositionSnapshotRenderer`: official Playwright → legacy `STUDIO_ANIMATION_BROWSER_FRAME_COMMAND` (unless disabled) → Imagick server path → `client_snapshot`. Parity is best-effort; fonts and embedded assets may differ between server and editor until locked paths succeed.

## Queues / runtime

- Processor, poll, finalize: `config('queue.ai_queue')` (default `ai`).
- Poll job retries with backoff; webhook path skips polling when ingest completes the job.
- Expect cold-start latency on first Playwright run after deploy.

## Webhooks

- `STUDIO_ANIMATION_WEBHOOK_INGEST_ENABLED`
- Shared secret header `X-Studio-Animation-Secret` when `STUDIO_ANIMATION_WEBHOOK_SECRET` is set
- Optional FAL HMAC: `STUDIO_ANIMATION_FAL_WEBHOOK_SECRET` + `X-Fal-Signature` (canonical body supported)

## Manual validation helpers

- `php artisan studio-animation:rollout-notes` — condensed checklist (this doc is the long form).
- `STUDIO_ANIMATION_DIAGNOSTICS_API=true` — adds `rollout_diagnostics` to animation job JSON (output row count, flags, queue name).

## Observability

- `[sa] <event>` — full context row (includes compact `drift_decision` string when present).
- `[sa_metric]` — duplicate flat line for log parsers: `event`, `job_id`, `status`, `provider`, `render_engine`, `renderer_version`, `drift_level`, `drift_decision`, `verified_webhook`, `retry_kind`, `finalize_reuse_mode`, `provider_submission_used_frame`, plus sparse extras (`exc`, `error_brief`, …).
- Toggle: `STUDIO_ANIMATION_OBSERVABILITY_ENABLED`, `STUDIO_ANIMATION_OBSERVABILITY_METRICS` (metric line; default on).

## Known limitations

- Single provider integration in app code paths (Kling/fal); transport `mock` for CI.
- Locked-frame parity vs editor snapshot is drift-classified, not pixel-guaranteed without official/browser success.
- Webhook + poll can both be enabled; first successful finalize wins; idempotent finalize prevents duplicate assets.
- Metrics are log-based only (no separate APM counters in this release).
