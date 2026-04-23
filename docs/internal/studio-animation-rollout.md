# Studio Animation 1.0 — rollout (internal)

## One output per job

- DB: unique `studio_animation_job_id` on `studio_animation_outputs`.
- Finalize always reuses the single row when fingerprint matches or when a legacy row exists (see `StudioAnimationCompletionService`).
- Full retry deletes that row and re-runs the pipeline from snapshot.

## Playwright (official renderer)

From the `jackpot/` app root:

```bash
npm ci
# Optional: verify planned browser + OS deps without installing (CI or hardened hosts)
npx playwright install --with-deps --dry-run chromium
npx playwright install --with-deps chromium
```

Canonical production worker checklist (Node, `npm ci`, full vs dry install): [Server requirements — Node.js / Playwright](../environments/SERVER_REQUIREMENTS.md#nodejs--playwright-video-heavy--studio).

Enable:

- `STUDIO_ANIMATION_OFFICIAL_PLAYWRIGHT_ENABLED=true`
- `STUDIO_ANIMATION_OFFICIAL_PLAYWRIGHT_SCRIPT` = absolute path to `scripts/studio-animation/playwright-locked-frame.mjs`
- Optional: `STUDIO_ANIMATION_OFFICIAL_PLAYWRIGHT_NODE`, `STUDIO_ANIMATION_OFFICIAL_PLAYWRIGHT_TIMEOUT`

## Fallback order

`CompositionSnapshotRenderer`: official Playwright → legacy `STUDIO_ANIMATION_BROWSER_FRAME_COMMAND` (unless disabled) → Imagick server path → `client_snapshot`. Parity is best-effort; fonts and embedded assets may differ between server and editor until locked paths succeed.

## Queues / runtime

- Processor, poll, finalize: `App\Support\StudioAnimationQueue::name()` — `ai` in staging/production; in `local`, the default connection’s list (usually `default`) so a plain `queue:work` consumes them. Override with `STUDIO_ANIMATION_QUEUE` or `studio_animation.dispatch_queue`.
- Poll job retries with backoff; webhook path skips polling when ingest completes the job.
- Expect cold-start latency on first Playwright run after deploy.

## Webhooks

- `STUDIO_ANIMATION_WEBHOOK_INGEST_ENABLED`
- Shared secret header `X-Studio-Animation-Secret` when `STUDIO_ANIMATION_WEBHOOK_SECRET` is set
- Optional inbound webhook HMAC: `STUDIO_ANIMATION_FAL_WEBHOOK_SECRET` + `X-Fal-Signature` (legacy header name; canonical body supported)

## Manual validation helpers

- `php artisan studio-animation:rollout-notes` — condensed checklist (this doc is the long form).
- `STUDIO_ANIMATION_DIAGNOSTICS_API=true` — adds `rollout_diagnostics` to animation job JSON (output row count, flags, queue name).

## Observability

- `[sa] <event>` — full context row (includes compact `drift_decision` string when present).
- `[sa_metric]` — duplicate flat line for log parsers: `event`, `job_id`, `status`, `provider`, `render_engine`, `renderer_version`, `drift_level`, `drift_decision`, `verified_webhook`, `retry_kind`, `finalize_reuse_mode`, `provider_submission_used_frame`, plus sparse extras (`exc`, `error_brief`, …).
- Toggle: `STUDIO_ANIMATION_OBSERVABILITY_ENABLED`, `STUDIO_ANIMATION_OBSERVABILITY_METRICS` (metric line; default on).

## Known limitations

- Direct Kling API only; transport `mock` in CI via config override.
- Locked-frame parity vs editor snapshot is drift-classified, not pixel-guaranteed without official/browser success.
- Webhook + poll can both be enabled; first successful finalize wins; idempotent finalize prevents duplicate assets.
- Metrics are log-based only (no separate APM counters in this release).
