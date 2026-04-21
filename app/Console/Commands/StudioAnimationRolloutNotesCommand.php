<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Internal operator notes for Studio Animation 1.0 validation (no side effects).
 */
final class StudioAnimationRolloutNotesCommand extends Command
{
    protected $signature = 'studio-animation:rollout-notes';

    protected $description = 'Print internal Studio Animation 1.0 rollout and manual-validation notes';

    public function handle(): int
    {
        $this->line('Studio Animation 1.0 — rollout & manual validation');
        $this->newLine();
        $this->line('Playwright (official locked frame)');
        $this->line('  • Repo root: npm ci && npx playwright install chromium');
        $this->line('  • STUDIO_ANIMATION_OFFICIAL_PLAYWRIGHT_ENABLED=true');
        $this->line('  • STUDIO_ANIMATION_OFFICIAL_PLAYWRIGHT_SCRIPT=/abs/path/to/jackpot/scripts/studio-animation/playwright-locked-frame.mjs');
        $this->line('  • Optional: STUDIO_ANIMATION_OFFICIAL_PLAYWRIGHT_REQUIRE_HIGH_FI=true (jobs need high_fidelity_submit)');
        $this->line('  • Optional: STUDIO_ANIMATION_OFFICIAL_PLAYWRIGHT_DISABLE_LEGACY=true (skip legacy browser command)');
        $this->newLine();
        $this->line('Fallback when official / browser paths fail');
        $this->line('  • Stack: official Playwright → legacy browser command (if enabled) → server_basic (Imagick) → client_snapshot');
        $this->line('  • client_snapshot: provider start frame matches editor export; drift metadata still computed when server paths run');
        $this->newLine();
        $this->line('Completion drivers');
        $this->line('  • Polling: PollStudioAnimationJob on queue '.config('queue.ai_queue', 'ai').' (primary when webhooks off)');
        $this->line('  • Webhook: STUDIO_ANIMATION_WEBHOOK_INGEST_ENABLED=true + shared secret / FAL HMAC; sets last_webhook_verified');
        $this->newLine();
        $this->line('Finalize retry idempotency');
        $this->line('  • Same remote URL fingerprint → reuse row; existing row without fingerprint → job_only reuse');
        $this->line('  • Failed job with pending_finalize_remote_video_url and finalize error → retry_kind finalize_only');
        $this->newLine();
        $this->line('Drift gate');
        $this->line('  • STUDIO_ANIMATION_DRIFT_GATE_ENABLED + MODE (warn_only | block_high | block_any) + STRICT');
        $this->line('  • Blocked jobs: error_code drift_blocked; logs [sa] processor_drift_blocked');
        $this->newLine();
        $this->line('API diagnostics (staging / manual QA)');
        $this->line('  • STUDIO_ANIMATION_DIAGNOSTICS_API=true → GET /app/studio/animations/{id} includes rollout_diagnostics');
        $this->newLine();
        $this->line('Observability');
        $this->line('  • STUDIO_ANIMATION_OBSERVABILITY_ENABLED=true (default) → grep logs for prefix [sa]');
        $this->line('  • STUDIO_ANIMATION_OBSERVABILITY_METRICS=true (default) → also grep [sa_metric] for flat rollout dimensions');
        $this->newLine();

        return self::SUCCESS;
    }
}
