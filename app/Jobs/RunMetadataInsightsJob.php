<?php

namespace App\Jobs;

use App\Exceptions\PlanLimitExceededException;
use App\Models\Tenant;
use App\Services\AI\Insights\FieldSuggestionEngine;
use App\Services\AI\Insights\InsightsAgentConstants;
use App\Services\AI\Insights\ValueSuggestionEngine;
use App\Services\AiUsageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Runs DB-backed metadata insight syncs (value + field suggestions) per tenant.
 *
 * Cost control: AiUsageService feature {@see InsightsAgentConstants::USAGE_FEATURE}, 24h cooldown,
 * tenant.ai_insights_enabled gate.
 */
class RunMetadataInsightsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $tenantId,
        public readonly bool $forceBypassCooldown = false
    ) {}

    public function handle(
        ValueSuggestionEngine $valueEngine,
        FieldSuggestionEngine $fieldEngine,
        AiUsageService $usageService
    ): void {
        $tenant = Tenant::query()->find($this->tenantId);
        if (! $tenant) {
            Log::warning('[RunMetadataInsightsJob] Tenant not found', ['tenant_id' => $this->tenantId]);

            return;
        }

        // Master AI kill switch (tenant.settings.ai_enabled) gates every AI pipeline.
        // Defaults to true when absent. Checked before the feature-specific flag so
        // logs clearly distinguish the master vs feature-specific paths.
        $tenantSettings = $tenant->settings ?? [];
        if (($tenantSettings['ai_enabled'] ?? true) === false) {
            Log::info('[RunMetadataInsightsJob] Skipped — master ai_enabled is false', [
                'tenant_id' => $tenant->id,
                'agent' => InsightsAgentConstants::AI_AGENT_INSIGHTS,
            ]);

            return;
        }

        if (! $tenant->ai_insights_enabled) {
            Log::info('[RunMetadataInsightsJob] Skipped — ai_insights_enabled is false', [
                'tenant_id' => $tenant->id,
                'agent' => InsightsAgentConstants::AI_AGENT_INSIGHTS,
            ]);

            return;
        }

        $cooldownKey = $this->cooldownCacheKey($tenant->id);
        if (! $this->forceBypassCooldown) {
            $lastRun = Cache::get($cooldownKey);
            if ($lastRun !== null) {
                $last = \Carbon\Carbon::parse($lastRun);
                if ($last->greaterThan(now()->subDay())) {
                    Log::info('[RunMetadataInsightsJob] Skipped — 24h cooldown', [
                        'tenant_id' => $tenant->id,
                        'last_run_at' => $lastRun,
                    ]);

                    return;
                }
            }
        }

        try {
            $usageService->checkUsage($tenant, InsightsAgentConstants::USAGE_FEATURE, 1);
        } catch (PlanLimitExceededException $e) {
            Log::notice('[RunMetadataInsightsJob] Skipped — usage cap', [
                'tenant_id' => $tenant->id,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        $valueInserted = 0;
        $fieldInserted = 0;

        try {
            $valueInserted = $valueEngine->sync($tenant->id);
            $fieldInserted = $fieldEngine->sync($tenant->id);
        } catch (\Throwable $e) {
            Log::error('[RunMetadataInsightsJob] Sync failed', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $usageService->trackUsage($tenant, InsightsAgentConstants::USAGE_FEATURE, 1);

        Cache::forever($cooldownKey, now()->toIso8601String());

        Log::info('[RunMetadataInsightsJob] Completed', [
            'tenant_id' => $tenant->id,
            'agent' => InsightsAgentConstants::AI_AGENT_INSIGHTS,
            'value_rows_inserted' => $valueInserted,
            'field_rows_inserted' => $fieldInserted,
            'force_cooldown_bypass' => $this->forceBypassCooldown,
        ]);
    }

    protected function cooldownCacheKey(int $tenantId): string
    {
        return "tenant:{$tenantId}:metadata_insights:last_run_at";
    }
}
