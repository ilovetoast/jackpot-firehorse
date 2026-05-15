<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\ContextualNavigation\ContextualNavigationAiReasoner;
use App\Services\ContextualNavigation\ContextualNavigationRecommender;
use App\Services\ContextualNavigation\ContextualNavigationStaleResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Phase 6 — generates contextual-navigation recommendations for one tenant.
 *
 * Lifecycle (matches `handle()` order; gated runs do nothing, mirroring the
 * "skipped runs are no-ops" contract used elsewhere in this app):
 *   1. Master feature gate (config `contextual_navigation_insights.enabled`).
 *   2. Tenant lookup + master AI gate (`tenant.settings['ai_enabled']`).
 *      The recommender is mostly statistical, but the master kill switch is
 *      a single point of governance.
 *   3. Insights gate (`tenants.ai_insights_enabled`).
 *   4. Min-asset gate (config `min_assets_per_tenant`).
 *   5. Cooldown gate (config `run_cooldown_hours`), bypassable via
 *      `forceBypassCooldown`.
 *   6. Stale resolver — runs BEFORE the recommender so newly-stale rows do
 *      not visually duplicate fresh ones in the review queue between the
 *      two phases. Manual stale-only flows may invoke the resolver directly.
 *   7. Statistical recommender pass.
 *   8. Optional AI rationale enrichment for borderline candidates only
 *      (config `use_ai_reasoning` + per-call `AiUsageService::checkUsage`).
 *      AI calls go strictly through `AIService::executeAgent`; this job
 *      never speaks to a provider directly.
 *   9. Cooldown stamp.
 */
class RunContextualNavigationInsightsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $tenantId,
        public readonly bool $forceBypassCooldown = false,
    ) {
        $queue = (string) config('contextual_navigation_insights.queue', 'default');
        $this->onQueue($queue);
    }

    public function handle(
        ContextualNavigationRecommender $recommender,
        ContextualNavigationStaleResolver $staleResolver,
        ContextualNavigationAiReasoner $reasoner,
    ): void {
        if (! (bool) config('contextual_navigation_insights.enabled', true)) {
            return;
        }

        $tenant = Tenant::query()->find($this->tenantId);
        if (! $tenant) {
            Log::warning('[RunContextualNavigationInsightsJob] Tenant not found', [
                'tenant_id' => $this->tenantId,
            ]);

            return;
        }

        $tenantSettings = $tenant->settings ?? [];
        if (($tenantSettings['ai_enabled'] ?? true) === false) {
            Log::info('[RunContextualNavigationInsightsJob] Skipped — master ai_enabled is false', [
                'tenant_id' => $tenant->id,
            ]);

            return;
        }

        if (! $tenant->ai_insights_enabled) {
            Log::info('[RunContextualNavigationInsightsJob] Skipped — ai_insights_enabled is false', [
                'tenant_id' => $tenant->id,
            ]);

            return;
        }

        $minAssets = (int) config('contextual_navigation_insights.min_assets_per_tenant', 50);
        if ($minAssets > 0) {
            $totalAssets = (int) \DB::table('assets')->where('tenant_id', $tenant->id)->count();
            if ($totalAssets < $minAssets) {
                Log::info('[RunContextualNavigationInsightsJob] Skipped — min_assets_per_tenant', [
                    'tenant_id' => $tenant->id,
                    'asset_count' => $totalAssets,
                    'required' => $minAssets,
                ]);

                return;
            }
        }

        // Cache key naming mirrors RunMetadataInsightsJob ("metadata_insights"
        // → full-noun, no abbreviation). Tenant-prefixed; safe across tenants.
        $cooldownKey = "tenant:{$tenant->id}:contextual_navigation_insights:last_run_at";
        $cooldownHours = (int) config('contextual_navigation_insights.run_cooldown_hours', 24 * 7);
        if (! $this->forceBypassCooldown && $cooldownHours > 0) {
            $lastRun = Cache::get($cooldownKey);
            if ($lastRun !== null) {
                $last = \Carbon\Carbon::parse($lastRun);
                if ($last->greaterThan(now()->subHours($cooldownHours))) {
                    Log::info('[RunContextualNavigationInsightsJob] Skipped — cooldown', [
                        'tenant_id' => $tenant->id,
                        'last_run_at' => $lastRun,
                    ]);

                    return;
                }
            }
        }

        // Stale resolver: cheap, deterministic. Run BEFORE the recommender
        // so newly-stale rows don't visually duplicate fresh ones in the
        // review queue between the two phases.
        try {
            $staleResolver->resolveForTenant($tenant);
        } catch (\Throwable $e) {
            Log::warning('[RunContextualNavigationInsightsJob] Stale resolver failed', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Statistical pass.
        $stats = ['written' => 0, 'skipped_folders' => 0, 'skipped_fields' => 0, 'candidates' => []];
        try {
            $stats = $recommender->run($tenant);
        } catch (\Throwable $e) {
            Log::error('[RunContextualNavigationInsightsJob] Recommender failed', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        // Optional AI enrichment for borderline candidates.
        $aiEnriched = 0;
        if ((bool) config('contextual_navigation_insights.use_ai_reasoning', false)
            && ! empty($stats['candidates'])
        ) {
            $band = (float) config('contextual_navigation_insights.ai_reasoning_borderline_band', 0.10);
            $cap = (int) config('contextual_navigation_insights.max_ai_calls_per_run', 5);
            $borderline = $recommender->selectBorderlineForAi($stats['candidates'], $band, $cap);
            try {
                $aiEnriched = $reasoner->enrich($tenant, $borderline);
            } catch (\Throwable $e) {
                // Reasoner already logs internally; keep the run successful
                // since the statistical recommendations are already written.
                Log::warning('[RunContextualNavigationInsightsJob] AI enrichment surfaced an exception', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Cache::forever($cooldownKey, now()->toIso8601String());

        Log::info('[RunContextualNavigationInsightsJob] Completed', [
            'tenant_id' => $tenant->id,
            'recommendations_written' => $stats['written'],
            'skipped_folders' => $stats['skipped_folders'],
            'skipped_fields' => $stats['skipped_fields'],
            'ai_enriched' => $aiEnriched,
            'force_cooldown_bypass' => $this->forceBypassCooldown,
        ]);
    }
}
