<?php

namespace App\Services;

use App\Models\Brand;
use Illuminate\Support\Facades\Cache;

/**
 * Brand Insight AI — rule-based human-readable insights.
 * No LLM, no config. Safe, low-cost, cached 10 min.
 */
class BrandInsightAI
{
    /**
     * Generate 1–2 human-readable insights from metrics.
     *
     * @return array<string>
     */
    public function generateInsights(array $metrics): array
    {
        $insights = [];

        // Skip "stagnant" copy for brand-new libraries (0 assets) — not the same as an established brand going quiet
        if (($metrics['total_assets'] ?? 0) > 0 && ($metrics['uploads_last_7_days'] ?? 0) === 0) {
            $insights[] = 'No new content added this week — consider uploading fresh assets.';
        }

        if (($metrics['shares_last_7_days'] ?? 0) > 10) {
            $insights[] = 'Sharing activity is high — your content is gaining traction.';
        }

        $completeness = $metrics['metadata_completeness'] ?? 1;
        if ($completeness < 0.5) {
            $insights[] = 'Many assets are missing metadata — improving this will boost discoverability.';
        }

        $aiRate = $metrics['ai_completion_rate'] ?? 1;
        if ($aiRate > 0 && $aiRate < 0.5) {
            $insights[] = 'AI suggestions are pending review — completing them improves asset quality.';
        }

        if (($metrics['uploads_last_7_days'] ?? 0) > 5) {
            $insights[] = 'Strong upload activity this week — keep the momentum going.';
        }

        return array_slice($insights, 0, 2);
    }

    /**
     * Get raw metrics for a brand (used by BrandInsightLLM).
     *
     * @return array<string, mixed>
     */
    public function getMetricsForBrand(Brand $brand): array
    {
        return $this->gatherMetrics($brand);
    }

    /**
     * Get cached insights for a brand.
     *
     * @return array<string>
     */
    public function getInsightsForBrand(Brand $brand): array
    {
        $cacheKey = "brand:{$brand->id}:ai-insights";

        return Cache::remember($cacheKey, 600, function () use ($brand) {
            $metrics = $this->gatherMetrics($brand);

            return $this->generateInsights($metrics);
        });
    }

    protected function gatherMetrics(Brand $brand): array
    {
        $tenant = $brand->tenant;
        if (! $tenant) {
            return [];
        }

        $cutoff = now()->subDays(7);

        $uploadEventTypes = [\App\Enums\EventType::ASSET_UPLOADED, \App\Enums\EventType::ASSET_UPLOAD_FINALIZED];

        $uploadsLast7Days = \App\Models\ActivityEvent::where('tenant_id', $tenant->id)
            ->where(function ($q) use ($brand) {
                $q->where('brand_id', $brand->id)->orWhereNull('brand_id');
            })
            ->whereIn('event_type', $uploadEventTypes)
            ->where('created_at', '>=', $cutoff)
            ->count();

        $sharesLast7Days = \App\Models\Download::where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('created_at', '>=', $cutoff)
            ->count();

        $analytics = app(MetadataAnalyticsService::class)->getAnalytics(
            $brand->tenant_id,
            $brand->id,
            null,
            null,
            null,
            false
        );
        $overview = $analytics['overview'] ?? [];
        $totalAssets = $overview['total_assets'] ?? 0;
        $assetsWithMetadata = $overview['assets_with_metadata'] ?? 0;
        $metadataCompleteness = $totalAssets > 0 ? $assetsWithMetadata / $totalAssets : 1;

        $aiTagPending = (int) \Illuminate\Support\Facades\DB::table('asset_tag_candidates')
            ->join('assets', 'asset_tag_candidates.asset_id', '=', 'assets.id')
            ->where('assets.tenant_id', $brand->tenant_id)
            ->where('assets.brand_id', $brand->id)
            ->where('asset_tag_candidates.producer', 'ai')
            ->whereNull('asset_tag_candidates.resolved_at')
            ->whereNull('asset_tag_candidates.dismissed_at')
            ->count();

        $aiCategoryPending = (int) \Illuminate\Support\Facades\DB::table('asset_metadata_candidates')
            ->join('assets', 'asset_metadata_candidates.asset_id', '=', 'assets.id')
            ->where('assets.tenant_id', $brand->tenant_id)
            ->where('assets.brand_id', $brand->id)
            ->whereNull('asset_metadata_candidates.resolved_at')
            ->whereNull('asset_metadata_candidates.dismissed_at')
            ->where('asset_metadata_candidates.producer', 'ai')
            ->count();

        $aiSuggestionsPending = $aiTagPending + $aiCategoryPending;

        $aiMetadataTotal = \Illuminate\Support\Facades\DB::table('asset_metadata_candidates')
            ->join('assets', 'asset_metadata_candidates.asset_id', '=', 'assets.id')
            ->where('assets.tenant_id', $brand->tenant_id)
            ->where('assets.brand_id', $brand->id)
            ->where('asset_metadata_candidates.producer', 'ai')
            ->count();
        $aiMetadataResolved = $aiMetadataTotal > 0
            ? \Illuminate\Support\Facades\DB::table('asset_metadata_candidates')
                ->join('assets', 'asset_metadata_candidates.asset_id', '=', 'assets.id')
                ->where('assets.tenant_id', $brand->tenant_id)
                ->where('assets.brand_id', $brand->id)
                ->where('asset_metadata_candidates.producer', 'ai')
                ->whereNotNull('asset_metadata_candidates.resolved_at')
                ->count()
            : 0;
        $aiCompletionRate = $aiMetadataTotal > 0 ? $aiMetadataResolved / $aiMetadataTotal : 1;

        $uploadsLast30Days = \App\Models\ActivityEvent::where('tenant_id', $tenant->id)
            ->where(function ($q) use ($brand) {
                $q->where('brand_id', $brand->id)->orWhereNull('brand_id');
            })
            ->whereIn('event_type', $uploadEventTypes)
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        $sharesLastWeek = \App\Models\Download::where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->whereBetween('created_at', [now()->subDays(14), $cutoff])
            ->count();
        $sharesTrend = $sharesLastWeek > 0
            ? (($sharesLast7Days - $sharesLastWeek) / $sharesLastWeek) * 100
            : null;

        return [
            'total_assets' => $totalAssets,
            'uploads_last_7_days' => $uploadsLast7Days,
            'uploads_last_30_days' => $uploadsLast30Days,
            'shares_last_7_days' => $sharesLast7Days,
            'shares_trend' => $sharesTrend !== null ? (($sharesTrend >= 0 ? '+' : '').round($sharesTrend, 1).'%') : null,
            'metadata_completeness' => round($metadataCompleteness, 2),
            'ai_suggestions_pending' => $aiSuggestionsPending,
            'ai_tags_pending' => $aiTagPending,
            'ai_categories_pending' => $aiCategoryPending,
            'ai_completion_rate' => round($aiCompletionRate, 2),
        ];
    }
}
