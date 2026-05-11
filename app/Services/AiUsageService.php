<?php

namespace App\Services;

use App\Enums\AITaskType;
use App\Exceptions\PlanLimitExceededException;
use App\Models\AIAgentRun;
use App\Services\Fal\FalModelPricingService;
use App\Models\StudioLayerExtractionSession;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AI Usage Service — Unified Credit Pool (Option A)
 *
 * Per-feature rows are preserved in ai_usage for analytics breakdowns.
 * Enforcement uses a single weighted credit budget:
 *   effective_cap = plan max_ai_credits_per_month + tenant.ai_credits_addon
 *   used_credits  = SUM(feature_calls * credit_weight) across all features this month
 *
 * Credit weights are defined in config/ai_credits.php.
 */
class AiUsageService
{
    /**
     * Track an AI call for a feature and enforce the shared credit budget.
     *
     * @param  string  $feature  Feature key (e.g. 'tagging', 'suggestions', 'generative_editor_images')
     * @param  int  $callCount  Number of calls to track
     *
     * @throws PlanLimitExceededException
     */
    public function trackUsage(Tenant $tenant, string $feature, int $callCount = 1): void
    {
        $today = now()->toDateString();

        DB::transaction(function () use ($tenant, $feature, $callCount, $today) {
            $creditWeight = $this->getCreditWeight($feature);
            $creditsRequired = $callCount * $creditWeight;

            $cap = $this->getEffectiveAiCredits($tenant);

            // 0 = unlimited (enterprise)
            if ($cap > 0) {
                $currentCredits = $this->getCreditUsageThisMonth($tenant);
                if (($currentCredits + $creditsRequired) > $cap) {
                    throw new PlanLimitExceededException(
                        'ai_credits',
                        $currentCredits,
                        $cap,
                        "Monthly AI credit budget exceeded. Used: {$currentCredits}, Cap: {$cap}, Requested: {$creditsRequired} ({$feature}). Usage resets at the start of next month."
                    );
                }
            }

            $this->upsertUsageRow($tenant, $feature, $callCount, $today);
        });

        Log::debug('[AiUsageService] Tracked AI usage', [
            'tenant_id' => $tenant->id,
            'feature' => $feature,
            'call_count' => $callCount,
            'credit_weight' => $this->getCreditWeight($feature),
            'date' => $today,
        ]);
    }

    /**
     * Bill successful remote Studio layer extraction once per session (row lock + metadata flag).
     * Skips if already recorded (idempotent for retries, duplicate jobs).
     */
    public function tryBillStudioLayerExtraction(
        Tenant $tenant,
        StudioLayerExtractionSession $session,
        string $model,
        string $remoteProvider,
    ): void {
        DB::transaction(function () use ($tenant, $session, $model, $remoteProvider): void {
            $s = StudioLayerExtractionSession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->first();
            if ($s === null) {
                return;
            }
            $m = is_array($s->metadata) ? $s->metadata : [];
            if (! empty($m['billed_studio_layer_extraction'])) {
                return;
            }
            $this->trackUsage($tenant, 'studio_layer_extraction', 1);
            $this->createStudioLayerProviderAgentRun(
                $tenant,
                (int) $s->brand_id,
                $s->user_id,
                AITaskType::STUDIO_LAYER_EXTRACTION,
                'studio_layer_extraction',
                'Studio layer extraction (remote segmentation)',
                (string) $s->id,
                $model,
                $remoteProvider,
                'studio_layer_extraction',
            );
            $m['billed_studio_layer_extraction'] = true;
            $s->update(['metadata' => $m]);
        });
    }

    /**
     * Bill successful background fill (e.g. Clipdrop) once per session.
     * Call only after a non-empty fill image and persisted background asset; idempotent.
     */
    public function tryBillStudioLayerBackgroundFill(
        Tenant $tenant,
        StudioLayerExtractionSession $session,
        string $inpaintProvider,
    ): void {
        if (! (bool) config('studio_layer_extraction.background_fill_credits_enabled', true)) {
            return;
        }
        DB::transaction(function () use ($tenant, $session, $inpaintProvider): void {
            $s = StudioLayerExtractionSession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->first();
            if ($s === null) {
                return;
            }
            $m = is_array($s->metadata) ? $s->metadata : [];
            if (! empty($m['billed_studio_layer_background_fill'])) {
                return;
            }
            $this->trackUsage($tenant, 'studio_layer_background_fill', 1);
            $this->createStudioLayerProviderAgentRun(
                $tenant,
                (int) $s->brand_id,
                $s->user_id,
                AITaskType::STUDIO_LAYER_BACKGROUND_FILL,
                'studio_layer_background_fill',
                'Studio layer background fill (inpaint)',
                (string) $s->id,
                'background_inpaint',
                $inpaintProvider,
                'studio_layer_background_fill',
            );
            $m['billed_studio_layer_background_fill'] = true;
            $s->update(['metadata' => $m]);
        });
    }

    private function createStudioLayerProviderAgentRun(
        Tenant $tenant,
        int $brandId,
        ?int $userId,
        string $taskType,
        string $agentId,
        string $agentName,
        string $extractionSessionId,
        string $modelUsed,
        string $remoteProvider,
        string $featureKey,
    ): void {
        $estimatedUsd = $this->resolveStudioLayerEstimatedUsd($taskType);
        $meta = [
            'tenant_id' => $tenant->id,
            'brand_id' => $brandId,
            'user_id' => $userId,
            'provider' => $remoteProvider,
            'model' => $modelUsed,
            'feature' => $featureKey,
            'extraction_session_id' => $extractionSessionId,
            'estimated_provider_usd' => $estimatedUsd,
            'estimated_cost_basis' => $taskType === AITaskType::STUDIO_LAYER_BACKGROUND_FILL
                ? 'config:studio_layer_extraction.cost_tracking.background_fill_estimated_usd'
                : 'fal_pricing_or_config:studio_layer_extraction.cost_tracking.extraction_fallback_estimated_usd',
        ];
        AIAgentRun::query()->create([
            'agent_id' => $agentId,
            'agent_name' => $agentName,
            'triggering_context' => 'user',
            'environment' => app()->environment(),
            'tenant_id' => $tenant->id,
            'user_id' => $userId,
            'task_type' => $taskType,
            'entity_type' => StudioLayerExtractionSession::class,
            'entity_id' => $extractionSessionId,
            'model_used' => $modelUsed !== '' ? $modelUsed : 'n/a',
            'tokens_in' => 0,
            'tokens_out' => 0,
            'estimated_cost' => $estimatedUsd,
            'status' => 'success',
            'started_at' => now(),
            'completed_at' => now(),
            'metadata' => $meta,
        ]);
    }

    private function resolveStudioLayerEstimatedUsd(string $taskType): float
    {
        if ($taskType === AITaskType::STUDIO_LAYER_BACKGROUND_FILL) {
            return (float) config('studio_layer_extraction.cost_tracking.background_fill_estimated_usd', 0.08);
        }

        $fal = app(FalModelPricingService::class);
        $fromFal = $fal->estimatedCostUsd();
        if ($fromFal !== null && $fromFal > 0) {
            return (float) $fromFal;
        }

        return (float) config('studio_layer_extraction.cost_tracking.extraction_fallback_estimated_usd', 0.05);
    }

    /**
     * Track AI usage with cost attribution (extended analytics).
     *
     * @throws PlanLimitExceededException
     */
    public function trackUsageWithCost(
        Tenant $tenant,
        string $feature,
        int $callCount = 1,
        float $costUsd = 0.0,
        ?int $tokensIn = null,
        ?int $tokensOut = null,
        ?string $model = null
    ): void {
        $today = now()->toDateString();

        DB::transaction(function () use ($tenant, $feature, $callCount, $costUsd, $tokensIn, $tokensOut, $model, $today) {
            $creditWeight = $this->getCreditWeight($feature);
            $creditsRequired = $callCount * $creditWeight;

            $cap = $this->getEffectiveAiCredits($tenant);

            if ($cap > 0) {
                $currentCredits = $this->getCreditUsageThisMonth($tenant);
                if (($currentCredits + $creditsRequired) > $cap) {
                    throw new PlanLimitExceededException(
                        'ai_credits',
                        $currentCredits,
                        $cap,
                        "Monthly AI credit budget exceeded. Used: {$currentCredits}, Cap: {$cap}, Requested: {$creditsRequired} ({$feature}). Usage resets at the start of next month."
                    );
                }
            }

            $existing = DB::table('ai_usage')
                ->where('tenant_id', $tenant->id)
                ->where('feature', $feature)
                ->where('usage_date', $today)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                DB::table('ai_usage')
                    ->where('id', $existing->id)
                    ->update([
                        'call_count' => DB::raw("call_count + {$callCount}"),
                        'cost_usd' => DB::raw('COALESCE(cost_usd, 0) + '.$costUsd),
                        'tokens_in' => DB::raw('COALESCE(tokens_in, 0) + '.($tokensIn ?? 0)),
                        'tokens_out' => DB::raw('COALESCE(tokens_out, 0) + '.($tokensOut ?? 0)),
                        'model' => $model ?? $existing->model,
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('ai_usage')->insert([
                    'tenant_id' => $tenant->id,
                    'feature' => $feature,
                    'usage_date' => $today,
                    'call_count' => $callCount,
                    'cost_usd' => $costUsd,
                    'tokens_in' => $tokensIn,
                    'tokens_out' => $tokensOut,
                    'model' => $model,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        Log::debug('[AiUsageService] Tracked AI usage with cost', [
            'tenant_id' => $tenant->id,
            'feature' => $feature,
            'call_count' => $callCount,
            'cost_usd' => $costUsd,
            'date' => $today,
        ]);
    }

    // -------------------------------------------------------------------------
    // Credit pool — the core of the unified budget
    // -------------------------------------------------------------------------

    /**
     * Total weighted credit usage this calendar month across all features.
     */
    public function getCreditUsageThisMonth(Tenant $tenant): int
    {
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();

        $rows = DB::table('ai_usage')
            ->where('tenant_id', $tenant->id)
            ->whereBetween('usage_date', [$monthStart, $monthEnd])
            ->select('feature', DB::raw('SUM(call_count) as total_calls'))
            ->groupBy('feature')
            ->get();

        $total = 0;
        foreach ($rows as $row) {
            $weight = $this->getCreditWeight($row->feature);
            $total += (int) $row->total_calls * $weight;
        }

        return $total;
    }

    /**
     * Total weighted credit usage for a specific month.
     */
    public function getCreditUsageForPeriod(Tenant $tenant, int $year, int $month): int
    {
        $start = Carbon::createFromDate($year, $month, 1)->startOfMonth()->toDateString();
        $end = Carbon::createFromDate($year, $month, 1)->endOfMonth()->toDateString();

        $rows = DB::table('ai_usage')
            ->where('tenant_id', $tenant->id)
            ->whereBetween('usage_date', [$start, $end])
            ->select('feature', DB::raw('SUM(call_count) as total_calls'))
            ->groupBy('feature')
            ->get();

        $total = 0;
        foreach ($rows as $row) {
            $weight = $this->getCreditWeight($row->feature);
            $total += (int) $row->total_calls * $weight;
        }

        return $total;
    }

    /**
     * Effective AI credit cap = plan base + purchased add-on.
     * Returns 0 for unlimited (enterprise).
     */
    public function getEffectiveAiCredits(Tenant $tenant): int
    {
        $planService = app(PlanService::class);
        $limits = $planService->getPlanLimits($tenant);
        $baseCap = (int) ($limits['max_ai_credits_per_month'] ?? 0);

        if ($baseCap === 0) {
            return 0; // unlimited
        }

        $addon = (int) ($tenant->ai_credits_addon ?? 0);

        return $baseCap + $addon;
    }

    /**
     * Credit weight for a feature from config/ai_credits.php.
     * Returns 1 as a safe default for unknown features.
     */
    public function getCreditWeight(string $feature): int
    {
        return (int) (config("ai_credits.weights.{$feature}") ?? 1);
    }

    /**
     * Compute video insights credit cost using per-minute tiered pricing.
     *
     * Formula: base_credits + max(0, ceil(duration_minutes) - 1) * per_additional_minute
     */
    public function getVideoInsightsCreditCost(float $billableMinutes): int
    {
        $base = (int) config('ai_credits.video_insights.base_credits', 5);
        $perMin = (int) config('ai_credits.video_insights.per_additional_minute', 3);

        $ceiledMinutes = (int) ceil(max(0.0, $billableMinutes));
        if ($ceiledMinutes < 1) {
            $ceiledMinutes = 1;
        }

        return $base + max(0, $ceiledMinutes - 1) * $perMin;
    }

    /**
     * Phase 4 (audio): credit cost for one audio AI analysis job (transcript +
     * mood + summary). Mirrors {@see getVideoInsightsCreditCost()} so the
     * audio pipeline integrates with the same unified credit pool / display.
     *
     * Cost is duration-tiered because Whisper bills per minute; the floor of
     * 1 minute means even a sub-minute voice memo still debits the base cost.
     *
     * @param  float  $billableMinutes  Duration of the audio asset in minutes.
     */
    public function getAudioInsightsCreditCost(float $billableMinutes): int
    {
        $base = (int) config('ai_credits.audio_insights.base_credits', 1);
        $perMin = (int) config('ai_credits.audio_insights.per_additional_minute', 1);

        $ceiledMinutes = (int) ceil(max(0.0, $billableMinutes));
        if ($ceiledMinutes < 1) {
            $ceiledMinutes = 1;
        }

        return $base + max(0, $ceiledMinutes - 1) * $perMin;
    }

    /**
     * Credits for one Studio composition animation job (duration-aware).
     *
     * Formula: base + max(0, duration_seconds - base_covers_seconds) * per_extra_second
     */
    public function getStudioAnimationCreditCost(int $durationSeconds): int
    {
        $base = (int) config('studio_animation.credits.base', 40);
        $covers = max(1, (int) config('studio_animation.credits.base_covers_seconds', 5));
        $perExtra = (int) config('studio_animation.credits.per_extra_second', 5);
        $d = max(1, $durationSeconds);
        $extra = max(0, $d - $covers);

        return $base + $extra * $perExtra;
    }

    /**
     * Which warning threshold (80/90/100) is the tenant at, or null if below all.
     */
    public function getCreditWarningLevel(Tenant $tenant): ?int
    {
        $cap = $this->getEffectiveAiCredits($tenant);
        if ($cap <= 0) {
            return null; // unlimited
        }

        $used = $this->getCreditUsageThisMonth($tenant);
        $percentage = ($used / $cap) * 100;

        $thresholds = config('ai_credits.warning_thresholds', [80, 90, 100]);
        rsort($thresholds);

        foreach ($thresholds as $threshold) {
            if ($percentage >= $threshold) {
                return $threshold;
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Compatibility layer — existing callers use these
    // -------------------------------------------------------------------------

    /**
     * Get current month's raw call count for a feature (for analytics display).
     */
    public function getMonthlyUsage(Tenant $tenant, string $feature): int
    {
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();

        return (int) DB::table('ai_usage')
            ->where('tenant_id', $tenant->id)
            ->where('feature', $feature)
            ->whereBetween('usage_date', [$monthStart, $monthEnd])
            ->sum('call_count');
    }

    /**
     * @deprecated Use getEffectiveAiCredits() for the unified cap.
     * Kept for backward compatibility; returns the unified credit cap.
     */
    public function getMonthlyCap(Tenant $tenant, string $feature): int
    {
        return $this->getEffectiveAiCredits($tenant);
    }

    /**
     * Check if tenant can afford credits for a feature call.
     */
    public function canUseFeature(Tenant $tenant, string $feature, int $requestedCalls = 1): bool
    {
        $cap = $this->getEffectiveAiCredits($tenant);
        if ($cap <= 0) {
            return true; // unlimited
        }

        $weight = $this->getCreditWeight($feature);
        $creditsNeeded = $requestedCalls * $weight;
        $used = $this->getCreditUsageThisMonth($tenant);

        return ($used + $creditsNeeded) <= $cap;
    }

    /**
     * Pre-flight check without tracking. Throws if over budget.
     *
     * @throws PlanLimitExceededException
     */
    public function checkUsage(Tenant $tenant, string $feature, int $requestedCalls = 1): void
    {
        if (($tenant->settings['ai_enabled'] ?? true) === false) {
            throw new PlanLimitExceededException(
                'ai_disabled',
                0,
                0,
                'AI features have been disabled for this workspace by an administrator.'
            );
        }

        $cap = $this->getEffectiveAiCredits($tenant);
        if ($cap <= 0) {
            return; // unlimited
        }

        $weight = $this->getCreditWeight($feature);
        $creditsNeeded = $requestedCalls * $weight;
        $currentCredits = $this->getCreditUsageThisMonth($tenant);

        if (($currentCredits + $creditsNeeded) > $cap) {
            throw new PlanLimitExceededException(
                'ai_credits',
                $currentCredits,
                $cap,
                "Monthly AI credit budget exceeded. Used: {$currentCredits}, Cap: {$cap}, Requested: {$creditsNeeded} ({$feature}). Usage resets at the start of next month."
            );
        }
    }

    /**
     * Unified usage status: per-feature breakdown + credit totals.
     */
    public function getUsageStatus(Tenant $tenant): array
    {
        $features = ['tagging', 'suggestions', 'photography_focal_point', 'brand_research', 'insights', 'generative_editor_images', 'generative_editor_edits', 'video_insights', 'audio_insights', 'pdf_extraction', 'presentation_preview', 'studio_animation', 'studio_layer_extraction', 'studio_layer_background_fill'];
        $perFeature = [];
        $totalCreditsUsed = 0;

        foreach ($features as $feature) {
            $calls = $this->getMonthlyUsage($tenant, $feature);
            $weight = $this->getCreditWeight($feature);
            $credits = $calls * $weight;
            $totalCreditsUsed += $credits;

            $perFeature[$feature] = [
                'calls' => $calls,
                'credit_weight' => $weight,
                'credits_used' => $credits,
            ];
        }

        $cap = $this->getEffectiveAiCredits($tenant);
        $remaining = $cap > 0 ? max(0, $cap - $totalCreditsUsed) : null;
        $percentage = $cap > 0 ? min(100, ($totalCreditsUsed / $cap) * 100) : 0;

        return [
            'credits_used' => $totalCreditsUsed,
            'credits_cap' => $cap,
            'credits_remaining' => $remaining,
            'credits_percentage' => round($percentage, 2),
            'is_unlimited' => $cap === 0,
            'is_exceeded' => $cap > 0 && $totalCreditsUsed >= $cap,
            'warning_level' => $this->getCreditWarningLevel($tenant),
            'per_feature' => $perFeature,
        ];
    }

    /**
     * Usage status for a specific month (historical).
     */
    public function getUsageStatusForPeriod(Tenant $tenant, int $year, int $month): array
    {
        $features = ['tagging', 'suggestions', 'photography_focal_point', 'brand_research', 'insights', 'generative_editor_images', 'generative_editor_edits', 'video_insights', 'audio_insights', 'pdf_extraction', 'presentation_preview', 'studio_animation', 'studio_layer_extraction', 'studio_layer_background_fill'];
        $perFeature = [];
        $totalCreditsUsed = 0;

        foreach ($features as $feature) {
            $calls = $this->getMonthlyUsageForPeriod($tenant, $feature, $year, $month);
            $weight = $this->getCreditWeight($feature);
            $credits = $calls * $weight;
            $totalCreditsUsed += $credits;

            $perFeature[$feature] = [
                'calls' => $calls,
                'credit_weight' => $weight,
                'credits_used' => $credits,
            ];
        }

        $cap = $this->getEffectiveAiCredits($tenant);
        $remaining = $cap > 0 ? max(0, $cap - $totalCreditsUsed) : null;
        $percentage = $cap > 0 ? min(100, ($totalCreditsUsed / $cap) * 100) : 0;

        return [
            'credits_used' => $totalCreditsUsed,
            'credits_cap' => $cap,
            'credits_remaining' => $remaining,
            'credits_percentage' => round($percentage, 2),
            'is_unlimited' => $cap === 0,
            'is_exceeded' => $cap > 0 && $totalCreditsUsed >= $cap,
            'per_feature' => $perFeature,
        ];
    }

    // -------------------------------------------------------------------------
    // Per-feature helpers (analytics / breakdown)
    // -------------------------------------------------------------------------

    public function getMonthlyUsageForPeriod(Tenant $tenant, string $feature, int $year, int $month): int
    {
        $start = Carbon::createFromDate($year, $month, 1)->startOfMonth()->toDateString();
        $end = Carbon::createFromDate($year, $month, 1)->endOfMonth()->toDateString();

        return (int) DB::table('ai_usage')
            ->where('tenant_id', $tenant->id)
            ->where('feature', $feature)
            ->whereBetween('usage_date', [$start, $end])
            ->sum('call_count');
    }

    public function getUsageBreakdown(Tenant $tenant, string $feature): array
    {
        return $this->getUsageBreakdownForPeriod($tenant, $feature, (int) now()->format('Y'), (int) now()->format('n'));
    }

    public function getUsageBreakdownForPeriod(Tenant $tenant, string $feature, int $year, int $month): array
    {
        $start = Carbon::createFromDate($year, $month, 1)->startOfMonth()->toDateString();
        $end = Carbon::createFromDate($year, $month, 1)->endOfMonth()->toDateString();

        $usage = DB::table('ai_usage')
            ->where('tenant_id', $tenant->id)
            ->where('feature', $feature)
            ->whereBetween('usage_date', [$start, $end])
            ->orderBy('usage_date')
            ->get(['usage_date', 'call_count']);

        return $usage->map(fn ($row) => [
            'date' => $row->usage_date,
            'calls' => (int) $row->call_count,
        ])->toArray();
    }

    // -------------------------------------------------------------------------
    // Video AI minutes (kept for backward compat with GenerateVideoInsightsJob)
    // -------------------------------------------------------------------------

    public function getVideoAiMinutesCap(Tenant $tenant): int
    {
        // Video minutes are now governed by the unified credit pool.
        // This returns 0 (unlimited) to disable the old per-minute hard cap;
        // credit enforcement happens in trackUsage/checkUsage instead.
        return 0;
    }

    public function getVideoAiMinutesUsedThisMonth(Tenant $tenant): float
    {
        $start = now()->startOfMonth();
        $end = now()->endOfMonth();

        $rows = AIAgentRun::query()
            ->where('tenant_id', $tenant->id)
            ->where('task_type', AITaskType::VIDEO_INSIGHTS)
            ->where('status', 'success')
            ->whereBetween('started_at', [$start, $end])
            ->get(['metadata']);

        $sum = 0.0;
        foreach ($rows as $row) {
            $meta = $row->metadata ?? [];
            $sum += (float) ($meta['billable_minutes'] ?? 0);
        }

        return round($sum, 4);
    }

    /**
     * @deprecated Video minutes are now enforced via the unified credit pool.
     * Kept as a no-op so existing callers don't break; they should migrate
     * to checkUsage($tenant, 'video_insights', ...) with credit-based tracking.
     */
    public function checkVideoAiMinuteBudget(Tenant $tenant, float $additionalBillableMinutes): void
    {
        // Credit-based enforcement: convert minutes to credits, then check pool.
        $creditsNeeded = $this->getVideoInsightsCreditCost($additionalBillableMinutes);
        $cap = $this->getEffectiveAiCredits($tenant);

        if ($cap <= 0) {
            return; // unlimited
        }

        $currentCredits = $this->getCreditUsageThisMonth($tenant);
        if (($currentCredits + $creditsNeeded) > $cap) {
            throw new PlanLimitExceededException(
                'ai_credits',
                $currentCredits,
                $cap,
                'Monthly AI credit budget exceeded for video insights. Usage resets at the start of next month.'
            );
        }
    }

    // -------------------------------------------------------------------------
    // Thumbnail enhancement metrics (unchanged — analytics only)
    // -------------------------------------------------------------------------

    /**
     * @return array{count: int, success_count: int, failed_count: int, skipped_count: int, success_rate: float|null, avg_duration_ms: float|null, p95_duration_ms: float|null}
     */
    public function getThumbnailEnhancementMetrics(Tenant $tenant): array
    {
        $start = now()->startOfMonth();
        $end = now()->endOfMonth();

        $rows = AIAgentRun::query()
            ->where('tenant_id', $tenant->id)
            ->where('task_type', AITaskType::THUMBNAIL_ENHANCEMENT)
            ->whereBetween('started_at', [$start, $end])
            ->whereNotNull('completed_at')
            ->get(['status', 'started_at', 'completed_at']);

        $count = $rows->count();
        if ($count === 0) {
            return [
                'count' => 0,
                'success_count' => 0,
                'failed_count' => 0,
                'skipped_count' => 0,
                'success_rate' => null,
                'avg_duration_ms' => null,
                'p95_duration_ms' => null,
            ];
        }

        $successCount = $rows->where('status', 'success')->count();
        $failedCount = $rows->where('status', 'failed')->count();
        $skippedCount = $rows->where('status', 'skipped')->count();

        $successOrFailed = $successCount + $failedCount;
        $successRate = $successOrFailed > 0
            ? round(100 * $successCount / $successOrFailed, 1)
            : null;

        $durations = [];
        foreach ($rows as $row) {
            if ($row->status === 'success' && $row->started_at && $row->completed_at) {
                $durations[] = $row->started_at->diffInMilliseconds($row->completed_at);
            }
        }

        $avgMs = $durations !== [] ? array_sum($durations) / count($durations) : null;

        $p95Ms = null;
        if ($durations !== []) {
            sort($durations, SORT_NUMERIC);
            $n = count($durations);
            $idx = (int) min($n - 1, max(0, (int) ceil(0.95 * $n) - 1));
            $p95Ms = $durations[$idx];
        }

        return [
            'count' => $count,
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'skipped_count' => $skippedCount,
            'success_rate' => $successRate,
            'avg_duration_ms' => $avgMs !== null ? round($avgMs, 1) : null,
            'p95_duration_ms' => $p95Ms !== null ? round((float) $p95Ms, 1) : null,
        ];
    }

    public function augmentAiUsageDashboardPayload(?array $aiUsageData, Tenant $tenant): ?array
    {
        if ($aiUsageData === null) {
            return null;
        }

        $m = $this->getThumbnailEnhancementMetrics($tenant);
        $aiUsageData['thumbnail_enhancement'] = [
            'count' => $m['count'],
            'success_count' => $m['success_count'],
            'failed_count' => $m['failed_count'],
            'skipped_count' => $m['skipped_count'],
            'success_rate' => $m['success_rate'],
            'avg_duration_ms' => $m['avg_duration_ms'],
            'p95_duration_ms' => $m['p95_duration_ms'],
        ];

        $videoAgg = app(AssetAiCostService::class)->getTenantVideoAiAggregate($tenant->id);
        $aiUsageData['video_ai'] = [
            'video_ai_cost_total' => $videoAgg['cost_usd'],
            'video_ai_jobs_count' => (int) $videoAgg['jobs_count'],
            'video_ai_minutes_used' => $videoAgg['minutes_billed'],
            'video_ai_minutes_cap' => 0,
        ];

        return $aiUsageData;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function upsertUsageRow(Tenant $tenant, string $feature, int $callCount, string $today): void
    {
        $existing = DB::table('ai_usage')
            ->where('tenant_id', $tenant->id)
            ->where('feature', $feature)
            ->where('usage_date', $today)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            DB::table('ai_usage')
                ->where('id', $existing->id)
                ->update([
                    'call_count' => DB::raw("call_count + {$callCount}"),
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('ai_usage')->insert([
                'tenant_id' => $tenant->id,
                'feature' => $feature,
                'usage_date' => $today,
                'call_count' => $callCount,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
