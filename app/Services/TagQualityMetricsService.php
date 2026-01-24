<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Tag Quality Metrics Service
 *
 * Phase J.2.6: Read-only analytics for AI tagging performance
 * 
 * Provides metrics derived from existing data to help company admins understand:
 * - How useful AI-generated tags are
 * - Which tags are accepted vs dismissed  
 * - Whether auto-applied tags are kept or removed
 * - How confidence correlates with acceptance
 * - Where AI tagging is noisy or valuable
 */
class TagQualityMetricsService
{
    /**
     * Cache TTL for metrics (15 minutes)
     */
    public const CACHE_TTL = 900;

    /**
     * Get overall tag quality summary for a tenant.
     *
     * @param Tenant $tenant
     * @param string $timeRange Format: 'YYYY-MM' (default: current month)
     * @return array
     */
    public function getSummaryMetrics(Tenant $tenant, string $timeRange = null): array
    {
        $timeRange = $timeRange ?? now()->format('Y-m');
        $cacheKey = "tag_quality_summary:{$tenant->id}:{$timeRange}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($tenant, $timeRange) {
            // Parse time range
            [$year, $month] = explode('-', $timeRange);
            $startDate = "{$year}-{$month}-01";
            $endDate = date('Y-m-t', strtotime($startDate)); // Last day of month

            // Get AI tag candidates data
            $candidatesData = $this->getCandidatesData($tenant, $startDate, $endDate);
            
            // Get applied tags data  
            $tagsData = $this->getTagsData($tenant, $startDate, $endDate);

            // Calculate core metrics
            return [
                'time_range' => $timeRange,
                'ai_enabled' => $this->isAiTaggingEnabled($tenant),
                
                // Core acceptance metrics
                'total_candidates' => $candidatesData['total'],
                'accepted_candidates' => $candidatesData['accepted'],
                'dismissed_candidates' => $candidatesData['dismissed'],
                'acceptance_rate' => $candidatesData['total'] > 0 ? 
                    round($candidatesData['accepted'] / $candidatesData['total'], 3) : 0,
                'dismissal_rate' => $candidatesData['total'] > 0 ? 
                    round($candidatesData['dismissed'] / $candidatesData['total'], 3) : 0,
                
                // Applied tags breakdown
                'total_tags' => $tagsData['total'],
                'manual_tags' => $tagsData['manual'],
                'ai_tags' => $tagsData['ai'], 
                'auto_applied_tags' => $tagsData['auto_applied'],
                'manual_ai_ratio' => $tagsData['ai'] > 0 ? 
                    round($tagsData['manual'] / $tagsData['ai'], 2) : null,
                
                // Auto-applied tag retention (simplified - would need removal tracking for full metric)
                'auto_applied_retention_note' => 'Retention tracking requires removal event logging',
                
                // Confidence analysis
                'avg_confidence_accepted' => $candidatesData['avg_confidence_accepted'],
                'avg_confidence_dismissed' => $candidatesData['avg_confidence_dismissed'],
                'confidence_correlation' => $candidatesData['avg_confidence_accepted'] > $candidatesData['avg_confidence_dismissed'],
            ];
        });
    }

    /**
     * Get tag-level quality metrics.
     *
     * @param Tenant $tenant
     * @param string $timeRange
     * @param int $limit
     * @return array
     */
    public function getTagMetrics(Tenant $tenant, string $timeRange = null, int $limit = 50): array
    {
        $timeRange = $timeRange ?? now()->format('Y-m');
        $cacheKey = "tag_quality_tags:{$tenant->id}:{$timeRange}:{$limit}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($tenant, $timeRange, $limit) {
            [$year, $month] = explode('-', $timeRange);
            $startDate = "{$year}-{$month}-01";
            $endDate = date('Y-m-t', strtotime($startDate));

            // Get per-tag metrics from candidates
            $tagMetrics = DB::table('asset_tag_candidates as atc')
                ->join('assets as a', 'atc.asset_id', '=', 'a.id')
                ->where('a.tenant_id', $tenant->id)
                ->where('atc.producer', 'ai')
                ->whereBetween('atc.created_at', [$startDate, $endDate])
                ->select([
                    'atc.tag',
                    DB::raw('COUNT(*) as total_generated'),
                    DB::raw('COUNT(CASE WHEN atc.resolved_at IS NOT NULL THEN 1 END) as accepted'),
                    DB::raw('COUNT(CASE WHEN atc.dismissed_at IS NOT NULL THEN 1 END) as dismissed'),
                    DB::raw('AVG(atc.confidence) as avg_confidence'),
                    DB::raw('AVG(CASE WHEN atc.resolved_at IS NOT NULL THEN atc.confidence END) as avg_confidence_accepted'),
                    DB::raw('AVG(CASE WHEN atc.dismissed_at IS NOT NULL THEN atc.confidence END) as avg_confidence_dismissed'),
                ])
                ->groupBy('atc.tag')
                ->orderByDesc('total_generated')
                ->limit($limit)
                ->get()
                ->map(function ($tag) {
                    $acceptanceRate = $tag->total_generated > 0 ? 
                        round($tag->accepted / $tag->total_generated, 3) : 0;
                    $dismissalRate = $tag->total_generated > 0 ? 
                        round($tag->dismissed / $tag->total_generated, 3) : 0;
                    
                    return [
                        'tag' => $tag->tag,
                        'total_generated' => (int) $tag->total_generated,
                        'accepted' => (int) $tag->accepted,
                        'dismissed' => (int) $tag->dismissed,
                        'acceptance_rate' => $acceptanceRate,
                        'dismissal_rate' => $dismissalRate,
                        'avg_confidence' => $tag->avg_confidence ? round($tag->avg_confidence, 3) : null,
                        'avg_confidence_accepted' => $tag->avg_confidence_accepted ? round($tag->avg_confidence_accepted, 3) : null,
                        'avg_confidence_dismissed' => $tag->avg_confidence_dismissed ? round($tag->avg_confidence_dismissed, 3) : null,
                        
                        // Trust signals
                        'trust_signals' => $this->calculateTrustSignals(
                            (int) $tag->total_generated,
                            (int) $tag->accepted, 
                            (int) $tag->dismissed,
                            $acceptanceRate,
                            $dismissalRate
                        ),
                    ];
                })
                ->toArray();

            return [
                'time_range' => $timeRange,
                'tags' => $tagMetrics,
                'total_tags' => count($tagMetrics),
            ];
        });
    }

    /**
     * Get confidence band analysis.
     *
     * @param Tenant $tenant
     * @param string $timeRange
     * @return array
     */
    public function getConfidenceMetrics(Tenant $tenant, string $timeRange = null): array
    {
        $timeRange = $timeRange ?? now()->format('Y-m');
        $cacheKey = "tag_quality_confidence:{$tenant->id}:{$timeRange}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($tenant, $timeRange) {
            [$year, $month] = explode('-', $timeRange);
            $startDate = "{$year}-{$month}-01";
            $endDate = date('Y-m-t', strtotime($startDate));

            // Define confidence bands
            $bands = [
                ['min' => 0.98, 'max' => 1.00, 'label' => '98-100%'],
                ['min' => 0.95, 'max' => 0.98, 'label' => '95-98%'],
                ['min' => 0.90, 'max' => 0.95, 'label' => '90-95%'],
                ['min' => 0.80, 'max' => 0.90, 'label' => '80-90%'],
                ['min' => 0.00, 'max' => 0.80, 'label' => 'Below 80%'],
            ];

            $confidenceData = [];
            
            foreach ($bands as $band) {
                $data = DB::table('asset_tag_candidates as atc')
                    ->join('assets as a', 'atc.asset_id', '=', 'a.id')
                    ->where('a.tenant_id', $tenant->id)
                    ->where('atc.producer', 'ai')
                    ->whereBetween('atc.created_at', [$startDate, $endDate])
                    ->where('atc.confidence', '>=', $band['min'])
                    ->where('atc.confidence', '<', $band['max'])
                    ->select([
                        DB::raw('COUNT(*) as total'),
                        DB::raw('COUNT(CASE WHEN atc.resolved_at IS NOT NULL THEN 1 END) as accepted'),
                        DB::raw('COUNT(CASE WHEN atc.dismissed_at IS NOT NULL THEN 1 END) as dismissed'),
                    ])
                    ->first();

                $acceptanceRate = $data->total > 0 ? round($data->accepted / $data->total, 3) : 0;

                $confidenceData[] = [
                    'confidence_band' => $band['label'],
                    'min_confidence' => $band['min'],
                    'max_confidence' => $band['max'],
                    'total_candidates' => (int) $data->total,
                    'accepted' => (int) $data->accepted,
                    'dismissed' => (int) $data->dismissed,
                    'acceptance_rate' => $acceptanceRate,
                ];
            }

            return [
                'time_range' => $timeRange,
                'confidence_bands' => $confidenceData,
            ];
        });
    }

    /**
     * Get trust signals for problematic patterns.
     *
     * @param Tenant $tenant
     * @param string $timeRange
     * @return array
     */
    public function getTrustSignals(Tenant $tenant, string $timeRange = null): array
    {
        $timeRange = $timeRange ?? now()->format('Y-m');
        
        // Get tag metrics first
        $tagMetrics = $this->getTagMetrics($tenant, $timeRange, 100);
        
        $signals = [
            'high_generation_low_acceptance' => [],
            'high_dismissal_frequency' => [],
            'zero_acceptance_tags' => [],
            'confidence_trust_drops' => [],
        ];

        foreach ($tagMetrics['tags'] as $tag) {
            // High generation + low acceptance (>10 generated, <30% accepted)
            if ($tag['total_generated'] >= 10 && $tag['acceptance_rate'] < 0.30) {
                $signals['high_generation_low_acceptance'][] = [
                    'tag' => $tag['tag'],
                    'generated' => $tag['total_generated'],
                    'acceptance_rate' => $tag['acceptance_rate'],
                ];
            }

            // High dismissal frequency (>50% dismissed)
            if ($tag['dismissal_rate'] > 0.50) {
                $signals['high_dismissal_frequency'][] = [
                    'tag' => $tag['tag'],
                    'generated' => $tag['total_generated'],
                    'dismissal_rate' => $tag['dismissal_rate'],
                ];
            }

            // Zero acceptance ever (>5 generated, 0% accepted)
            if ($tag['total_generated'] >= 5 && $tag['acceptance_rate'] == 0) {
                $signals['zero_acceptance_tags'][] = [
                    'tag' => $tag['tag'],
                    'generated' => $tag['total_generated'],
                ];
            }

            // Confidence vs trust drops (high confidence but low acceptance)
            if ($tag['avg_confidence'] >= 0.90 && $tag['acceptance_rate'] < 0.40) {
                $signals['confidence_trust_drops'][] = [
                    'tag' => $tag['tag'],
                    'avg_confidence' => $tag['avg_confidence'],
                    'acceptance_rate' => $tag['acceptance_rate'],
                ];
            }
        }

        return [
            'time_range' => $timeRange,
            'signals' => $signals,
            'summary' => [
                'total_problematic_tags' => count($signals['high_generation_low_acceptance']) +
                                          count($signals['high_dismissal_frequency']) +
                                          count($signals['zero_acceptance_tags']) +
                                          count($signals['confidence_trust_drops']),
            ],
        ];
    }

    /**
     * Clear metrics cache for a tenant.
     *
     * @param Tenant $tenant
     * @return void
     */
    public function clearCache(Tenant $tenant): void
    {
        // Clear all cached metrics for this tenant
        $pattern = "tag_quality_*:{$tenant->id}:*";
        
        // Note: This is a simple implementation. In production, you might want
        // to use a more sophisticated cache clearing strategy.
        Cache::flush(); // Temporary - clears all cache
    }

    /**
     * Check if AI tagging is enabled for tenant.
     *
     * @param Tenant $tenant
     * @return bool
     */
    protected function isAiTaggingEnabled(Tenant $tenant): bool
    {
        // Use the existing policy service to check if AI is enabled
        $policyService = app(\App\Services\AiTagPolicyService::class);
        return $policyService->isAiTaggingEnabled($tenant);
    }

    /**
     * Get candidates data for metrics calculation.
     *
     * @param Tenant $tenant
     * @param string $startDate
     * @param string $endDate
     * @return array
     */
    protected function getCandidatesData(Tenant $tenant, string $startDate, string $endDate): array
    {
        $data = DB::table('asset_tag_candidates as atc')
            ->join('assets as a', 'atc.asset_id', '=', 'a.id')
            ->where('a.tenant_id', $tenant->id)
            ->where('atc.producer', 'ai')
            ->whereBetween('atc.created_at', [$startDate, $endDate])
            ->select([
                DB::raw('COUNT(*) as total'),
                DB::raw('COUNT(CASE WHEN atc.resolved_at IS NOT NULL THEN 1 END) as accepted'),
                DB::raw('COUNT(CASE WHEN atc.dismissed_at IS NOT NULL THEN 1 END) as dismissed'),
                DB::raw('AVG(atc.confidence) as avg_confidence'),
                DB::raw('AVG(CASE WHEN atc.resolved_at IS NOT NULL THEN atc.confidence END) as avg_confidence_accepted'),
                DB::raw('AVG(CASE WHEN atc.dismissed_at IS NOT NULL THEN atc.confidence END) as avg_confidence_dismissed'),
            ])
            ->first();

        return [
            'total' => (int) $data->total,
            'accepted' => (int) $data->accepted,
            'dismissed' => (int) $data->dismissed,
            'avg_confidence' => $data->avg_confidence ? round($data->avg_confidence, 3) : null,
            'avg_confidence_accepted' => $data->avg_confidence_accepted ? round($data->avg_confidence_accepted, 3) : null,
            'avg_confidence_dismissed' => $data->avg_confidence_dismissed ? round($data->avg_confidence_dismissed, 3) : null,
        ];
    }

    /**
     * Get applied tags data for metrics calculation.
     *
     * @param Tenant $tenant
     * @param string $startDate
     * @param string $endDate
     * @return array
     */
    protected function getTagsData(Tenant $tenant, string $startDate, string $endDate): array
    {
        $data = DB::table('asset_tags as at')
            ->join('assets as a', 'at.asset_id', '=', 'a.id')
            ->where('a.tenant_id', $tenant->id)
            ->whereBetween('at.created_at', [$startDate, $endDate])
            ->select([
                DB::raw('COUNT(*) as total'),
                DB::raw('COUNT(CASE WHEN at.source = \'manual\' THEN 1 END) as manual_count'),
                DB::raw('COUNT(CASE WHEN at.source IN (\'ai\', \'ai:auto\') THEN 1 END) as ai_count'),
                DB::raw('COUNT(CASE WHEN at.source = \'ai:auto\' THEN 1 END) as auto_applied_count'),
            ])
            ->first();

        return [
            'total' => (int) $data->total,
            'manual' => (int) $data->manual_count,
            'ai' => (int) $data->ai_count,
            'auto_applied' => (int) $data->auto_applied_count,
        ];
    }

    /**
     * Calculate trust signals for a specific tag.
     *
     * @param int $totalGenerated
     * @param int $accepted
     * @param int $dismissed
     * @param float $acceptanceRate
     * @param float $dismissalRate
     * @return array
     */
    protected function calculateTrustSignals(int $totalGenerated, int $accepted, int $dismissed, float $acceptanceRate, float $dismissalRate): array
    {
        $signals = [];

        if ($totalGenerated >= 10 && $acceptanceRate < 0.30) {
            $signals[] = 'high_generation_low_acceptance';
        }

        if ($dismissalRate > 0.50) {
            $signals[] = 'high_dismissal_frequency';
        }

        if ($totalGenerated >= 5 && $acceptanceRate == 0) {
            $signals[] = 'zero_acceptance';
        }

        return $signals;
    }
}