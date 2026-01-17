<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Metadata Analytics Service
 *
 * Phase 7: Provides read-only insights into metadata quality, coverage, and usage.
 *
 * Rules:
 * - All analytics are READ-ONLY
 * - All metrics are tenant-scoped
 * - No data mutation
 * - All queries must be explainable
 */
class MetadataAnalyticsService
{
    /**
     * Get comprehensive analytics for a tenant.
     *
     * @param int $tenantId
     * @param int|null $brandId Optional brand filter
     * @param int|null $categoryId Optional category filter
     * @param string|null $startDate Optional date range start (Y-m-d)
     * @param string|null $endDate Optional date range end (Y-m-d)
     * @param bool $includeInternal Whether to include internal-only fields (admin only)
     * @return array Analytics data
     */
    public function getAnalytics(
        int $tenantId,
        ?int $brandId = null,
        ?int $categoryId = null,
        ?string $startDate = null,
        ?string $endDate = null,
        bool $includeInternal = false
    ): array {
        return [
            'overview' => $this->getOverview($tenantId, $brandId, $categoryId, $startDate, $endDate),
            'coverage' => $this->getCoverage($tenantId, $brandId, $categoryId, $includeInternal),
            'required_compliance' => $this->getRequiredCompliance($tenantId, $brandId, $categoryId),
            'ai_effectiveness' => $this->getAiEffectiveness($tenantId, $brandId, $categoryId, $startDate, $endDate),
            'freshness' => $this->getFreshness($tenantId, $brandId, $categoryId),
            'rights_risk' => $this->getRightsRisk($tenantId, $brandId, $categoryId),
            'governance_gaps' => $this->getGovernanceGaps($tenantId, $brandId, $categoryId),
        ];
    }

    /**
     * Get overview KPIs.
     *
     * @param int $tenantId
     * @param int|null $brandId
     * @param int|null $categoryId
     * @param string|null $startDate
     * @param string|null $endDate
     * @return array
     */
    protected function getOverview(
        int $tenantId,
        ?int $brandId,
        ?int $categoryId,
        ?string $startDate,
        ?string $endDate
    ): array {
        // Base asset query
        $assetQuery = DB::table('assets')
            ->where('assets.tenant_id', $tenantId)
            ->whereNull('assets.deleted_at')
            ->where('assets.status', 'visible');

        if ($brandId) {
            $assetQuery->where('assets.brand_id', $brandId);
        }

        if ($categoryId) {
            $assetQuery->whereJsonContains('assets.metadata->category_id', $categoryId);
        }

        if ($startDate) {
            $assetQuery->where('assets.created_at', '>=', $startDate);
        }

        if ($endDate) {
            $assetQuery->where('assets.created_at', '<=', $endDate . ' 23:59:59');
        }

        $totalAssets = $assetQuery->count();

        // Assets with at least one approved metadata value
        $assetsWithMetadata = (clone $assetQuery)
            ->join('asset_metadata', 'assets.id', '=', 'asset_metadata.asset_id')
            ->whereNotNull('asset_metadata.approved_at')
            ->distinct('assets.id')
            ->count('assets.id');

        // Total approved metadata values
        $totalMetadataValues = DB::table('asset_metadata')
            ->join('assets', 'asset_metadata.asset_id', '=', 'assets.id')
            ->where('assets.tenant_id', $tenantId)
            ->whereNull('assets.deleted_at')
            ->where('assets.status', 'visible')
            ->whereNotNull('asset_metadata.approved_at');

        if ($brandId) {
            $totalMetadataValues->where('assets.brand_id', $brandId);
        }

        if ($categoryId) {
            $totalMetadataValues->whereJsonContains('assets.metadata->category_id', $categoryId);
        }

        if ($startDate) {
            $totalMetadataValues->where('assets.created_at', '>=', $startDate);
        }

        if ($endDate) {
            $totalMetadataValues->where('assets.created_at', '<=', $endDate . ' 23:59:59');
        }

        $totalMetadataValues = $totalMetadataValues->count();

        // Metadata completeness percentage
        $completeness = $totalAssets > 0
            ? round(($assetsWithMetadata / $totalAssets) * 100, 2)
            : 0;

        // Average metadata values per asset
        $avgMetadataPerAsset = $totalAssets > 0
            ? round($totalMetadataValues / $totalAssets, 2)
            : 0;

        return [
            'total_assets' => $totalAssets,
            'assets_with_metadata' => $assetsWithMetadata,
            'total_metadata_values' => $totalMetadataValues,
            'completeness_percentage' => $completeness,
            'avg_metadata_per_asset' => $avgMetadataPerAsset,
        ];
    }

    /**
     * Get metadata coverage metrics.
     *
     * @param int $tenantId
     * @param int|null $brandId
     * @param int|null $categoryId
     * @param bool $includeInternal
     * @return array
     */
    protected function getCoverage(
        int $tenantId,
        ?int $brandId,
        ?int $categoryId,
        bool $includeInternal
    ): array {
        // Get total assets
        $assetQuery = DB::table('assets')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->where('status', 'visible');

        if ($brandId) {
            $assetQuery->where('brand_id', $brandId);
        }

        if ($categoryId) {
            $assetQuery->whereJsonContains('metadata->category_id', $categoryId);
        }

        $totalAssets = $assetQuery->count();

        if ($totalAssets === 0) {
            return [
                'total_assets' => 0,
                'field_coverage' => [],
                'lowest_coverage_fields' => [],
            ];
        }

        // Get all visible fields (excluding internal unless admin)
        $fieldQuery = DB::table('metadata_fields')
            ->where('scope', 'system')
            ->whereNull('deprecated_at');

        if (!$includeInternal) {
            $fieldQuery->where('is_internal_only', false);
        }

        $fields = $fieldQuery->get(['id', 'key', 'system_label', 'type']);

        $fieldCoverage = [];
        $lowestCoverage = [];

        foreach ($fields as $field) {
            // Count assets with approved values for this field
            $assetsWithField = DB::table('asset_metadata')
                ->join('assets', 'asset_metadata.asset_id', '=', 'assets.id')
                ->where('assets.tenant_id', $tenantId)
                ->whereNull('assets.deleted_at')
                ->where('assets.status', 'visible')
                ->where('asset_metadata.metadata_field_id', $field->id)
                ->whereNotNull('asset_metadata.approved_at');

            if ($brandId) {
                $assetsWithField->where('assets.brand_id', $brandId);
            }

            if ($categoryId) {
                $assetsWithField->whereJsonContains('assets.metadata->category_id', $categoryId);
            }

            $count = $assetsWithField->distinct('assets.id')->count('assets.id');
            $percentage = round(($count / $totalAssets) * 100, 2);

            $fieldCoverage[] = [
                'field_id' => $field->id,
                'field_key' => $field->key,
                'field_label' => $field->system_label,
                'field_type' => $field->type,
                'assets_with_value' => $count,
                'coverage_percentage' => $percentage,
            ];

            $lowestCoverage[] = [
                'field_key' => $field->key,
                'field_label' => $field->system_label,
                'coverage_percentage' => $percentage,
            ];
        }

        // Sort by coverage percentage (ascending)
        usort($lowestCoverage, fn($a, $b) => $a['coverage_percentage'] <=> $b['coverage_percentage']);
        $lowestCoverage = array_slice($lowestCoverage, 0, 10); // Top 10 lowest

        return [
            'total_assets' => $totalAssets,
            'field_coverage' => $fieldCoverage,
            'lowest_coverage_fields' => $lowestCoverage,
        ];
    }

    /**
     * Get required field compliance metrics.
     *
     * @param int $tenantId
     * @param int|null $brandId
     * @param int|null $categoryId
     * @return array
     */
    protected function getRequiredCompliance(
        int $tenantId,
        ?int $brandId,
        ?int $categoryId
    ): array {
        // This requires resolving schema per category
        // For now, return basic metrics
        // TODO: Integrate with MetadataSchemaResolver to get required fields per category

        $assetQuery = DB::table('assets')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->where('status', 'visible');

        if ($brandId) {
            $assetQuery->where('brand_id', $brandId);
        }

        if ($categoryId) {
            $assetQuery->whereJsonContains('metadata->category_id', $categoryId);
        }

        $totalAssets = $assetQuery->count();

        // For now, return placeholder structure
        // Full implementation would require schema resolution per category
        return [
            'total_assets' => $totalAssets,
            'assets_missing_required' => 0, // Placeholder
            'compliance_percentage' => 100, // Placeholder
            'top_missing_fields' => [], // Placeholder
        ];
    }

    /**
     * Get AI suggestion effectiveness metrics.
     *
     * @param int $tenantId
     * @param int|null $brandId
     * @param int|null $categoryId
     * @param string|null $startDate
     * @param string|null $endDate
     * @return array
     */
    protected function getAiEffectiveness(
        int $tenantId,
        ?int $brandId,
        ?int $categoryId,
        ?string $startDate,
        ?string $endDate
    ): array {
        // Base query for AI-sourced metadata
        $aiQuery = DB::table('asset_metadata')
            ->join('assets', 'asset_metadata.asset_id', '=', 'assets.id')
            ->where('assets.tenant_id', $tenantId)
            ->whereNull('assets.deleted_at')
            ->where('assets.status', 'visible')
            ->where('asset_metadata.source', 'ai');

        if ($brandId) {
            $aiQuery->where('assets.brand_id', $brandId);
        }

        if ($categoryId) {
            $aiQuery->whereJsonContains('assets.metadata->category_id', $categoryId);
        }

        if ($startDate) {
            $aiQuery->where('asset_metadata.created_at', '>=', $startDate);
        }

        if ($endDate) {
            $aiQuery->where('asset_metadata.created_at', '<=', $endDate . ' 23:59:59');
        }

        $totalSuggestions = (clone $aiQuery)->count();

        // Approved suggestions
        $approvedSuggestions = (clone $aiQuery)
            ->whereNotNull('asset_metadata.approved_at')
            ->count();

        // Rejected suggestions (check history for rejections)
        $rejectedSuggestions = DB::table('asset_metadata_history')
            ->join('asset_metadata', 'asset_metadata_history.asset_metadata_id', '=', 'asset_metadata.id')
            ->join('assets', 'asset_metadata.asset_id', '=', 'assets.id')
            ->where('assets.tenant_id', $tenantId)
            ->whereNull('assets.deleted_at')
            ->where('assets.status', 'visible')
            ->where('asset_metadata.source', 'ai')
            ->where('asset_metadata_history.source', 'ai_rejected');

        if ($brandId) {
            $rejectedSuggestions->where('assets.brand_id', $brandId);
        }

        if ($categoryId) {
            $rejectedSuggestions->whereJsonContains('assets.metadata->category_id', $categoryId);
        }

        if ($startDate) {
            $rejectedSuggestions->where('asset_metadata_history.created_at', '>=', $startDate);
        }

        if ($endDate) {
            $rejectedSuggestions->where('asset_metadata_history.created_at', '<=', $endDate . ' 23:59:59');
        }

        $rejectedCount = $rejectedSuggestions->count();

        // Acceptance rate
        $acceptanceRate = $totalSuggestions > 0
            ? round(($approvedSuggestions / $totalSuggestions) * 100, 2)
            : 0;

        // Rejection rate
        $rejectionRate = $totalSuggestions > 0
            ? round(($rejectedCount / $totalSuggestions) * 100, 2)
            : 0;

        // Average confidence for approved vs rejected
        $avgConfidenceApproved = (clone $aiQuery)
            ->whereNotNull('asset_metadata.approved_at')
            ->whereNotNull('asset_metadata.confidence')
            ->avg('asset_metadata.confidence');

        $avgConfidenceRejected = (clone $aiQuery)
            ->whereNull('asset_metadata.approved_at')
            ->whereNotNull('asset_metadata.confidence')
            ->avg('asset_metadata.confidence');

        return [
            'total_suggestions' => $totalSuggestions,
            'approved_suggestions' => $approvedSuggestions,
            'rejected_suggestions' => $rejectedCount,
            'acceptance_rate' => $acceptanceRate,
            'rejection_rate' => $rejectionRate,
            'avg_confidence_approved' => $avgConfidenceApproved ? round($avgConfidenceApproved, 2) : null,
            'avg_confidence_rejected' => $avgConfidenceRejected ? round($avgConfidenceRejected, 2) : null,
        ];
    }

    /**
     * Get metadata freshness metrics.
     *
     * @param int $tenantId
     * @param int|null $brandId
     * @param int|null $categoryId
     * @return array
     */
    protected function getFreshness(
        int $tenantId,
        ?int $brandId,
        ?int $categoryId
    ): array {
        // Get last updated timestamp per field
        $lastUpdated = DB::table('asset_metadata')
            ->join('assets', 'asset_metadata.asset_id', '=', 'assets.id')
            ->join('metadata_fields', 'asset_metadata.metadata_field_id', '=', 'metadata_fields.id')
            ->where('assets.tenant_id', $tenantId)
            ->whereNull('assets.deleted_at')
            ->where('assets.status', 'visible')
            ->whereNotNull('asset_metadata.approved_at');

        if ($brandId) {
            $lastUpdated->where('assets.brand_id', $brandId);
        }

        if ($categoryId) {
            $lastUpdated->whereJsonContains('assets.metadata->category_id', $categoryId);
        }

        $lastUpdated = $lastUpdated
            ->select(
                'metadata_fields.key as field_key',
                'metadata_fields.system_label as field_label',
                DB::raw('MAX(asset_metadata.updated_at) as last_updated')
            )
            ->groupBy('metadata_fields.id', 'metadata_fields.key', 'metadata_fields.system_label')
            ->get();

        // Assets with stale metadata (> 90 days)
        $staleThreshold = now()->subDays(90);
        $staleAssets = DB::table('assets')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->where('status', 'visible')
            ->whereExists(function ($query) use ($staleThreshold, $brandId, $categoryId) {
                $query->select(DB::raw(1))
                    ->from('asset_metadata')
                    ->whereColumn('asset_metadata.asset_id', 'assets.id')
                    ->whereNotNull('asset_metadata.approved_at')
                    ->where('asset_metadata.updated_at', '<', $staleThreshold);

                if ($brandId) {
                    $query->where('assets.brand_id', $brandId);
                }

                if ($categoryId) {
                    $query->whereJsonContains('assets.metadata->category_id', $categoryId);
                }
            })
            ->count();

        return [
            'last_updated_by_field' => $lastUpdated->map(function ($item) {
                return [
                    'field_key' => $item->field_key,
                    'field_label' => $item->field_label,
                    'last_updated' => $item->last_updated,
                    'days_ago' => $item->last_updated
                        ? round(now()->diffInDays($item->last_updated), 0)
                        : null,
                ];
            })->toArray(),
            'stale_assets_count' => $staleAssets,
            'stale_threshold_days' => 90,
        ];
    }

    /**
     * Get rights and risk indicators.
     *
     * @param int $tenantId
     * @param int|null $brandId
     * @param int|null $categoryId
     * @return array
     */
    protected function getRightsRisk(
        int $tenantId,
        ?int $brandId,
        ?int $categoryId
    ): array {
        // Find expiration_date field
        $expirationField = DB::table('metadata_fields')
            ->where('key', 'expiration_date')
            ->where('scope', 'system')
            ->first();

        if (!$expirationField) {
            return [
                'expired_count' => 0,
                'expiring_30_days' => 0,
                'expiring_60_days' => 0,
                'expiring_90_days' => 0,
                'usage_rights_distribution' => [],
            ];
        }

        $assetQuery = DB::table('assets')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->where('status', 'visible');

        if ($brandId) {
            $assetQuery->where('brand_id', $brandId);
        }

        if ($categoryId) {
            $assetQuery->whereJsonContains('metadata->category_id', $categoryId);
        }

        // Assets with expired expiration_date
        $expiredCount = (clone $assetQuery)
            ->join('asset_metadata', 'assets.id', '=', 'asset_metadata.asset_id')
            ->where('asset_metadata.metadata_field_id', $expirationField->id)
            ->whereNotNull('asset_metadata.approved_at')
            ->whereRaw("JSON_EXTRACT(asset_metadata.value_json, '$') < ?", [now()->toDateString()])
            ->distinct('assets.id')
            ->count('assets.id');

        // Expiring in next 30/60/90 days
        $expiring30 = (clone $assetQuery)
            ->join('asset_metadata', 'assets.id', '=', 'asset_metadata.asset_id')
            ->where('asset_metadata.metadata_field_id', $expirationField->id)
            ->whereNotNull('asset_metadata.approved_at')
            ->whereRaw("JSON_EXTRACT(asset_metadata.value_json, '$') BETWEEN ? AND ?", [
                now()->toDateString(),
                now()->addDays(30)->toDateString(),
            ])
            ->distinct('assets.id')
            ->count('assets.id');

        $expiring60 = (clone $assetQuery)
            ->join('asset_metadata', 'assets.id', '=', 'asset_metadata.asset_id')
            ->where('asset_metadata.metadata_field_id', $expirationField->id)
            ->whereNotNull('asset_metadata.approved_at')
            ->whereRaw("JSON_EXTRACT(asset_metadata.value_json, '$') BETWEEN ? AND ?", [
                now()->toDateString(),
                now()->addDays(60)->toDateString(),
            ])
            ->distinct('assets.id')
            ->count('assets.id');

        $expiring90 = (clone $assetQuery)
            ->join('asset_metadata', 'assets.id', '=', 'asset_metadata.asset_id')
            ->where('asset_metadata.metadata_field_id', $expirationField->id)
            ->whereNotNull('asset_metadata.approved_at')
            ->whereRaw("JSON_EXTRACT(asset_metadata.value_json, '$') BETWEEN ? AND ?", [
                now()->toDateString(),
                now()->addDays(90)->toDateString(),
            ])
            ->distinct('assets.id')
            ->count('assets.id');

        // Usage rights distribution
        $usageRightsField = DB::table('metadata_fields')
            ->where('key', 'usage_rights')
            ->where('scope', 'system')
            ->first();

        $usageRightsDistribution = [];
        if ($usageRightsField) {
            $distribution = DB::table('asset_metadata')
                ->join('assets', 'asset_metadata.asset_id', '=', 'assets.id')
                ->where('assets.tenant_id', $tenantId)
                ->whereNull('assets.deleted_at')
                ->where('assets.status', 'visible')
                ->where('asset_metadata.metadata_field_id', $usageRightsField->id)
                ->whereNotNull('asset_metadata.approved_at');

            if ($brandId) {
                $distribution->where('assets.brand_id', $brandId);
            }

            if ($categoryId) {
                $distribution->whereJsonContains('assets.metadata->category_id', $categoryId);
            }

            $distribution = $distribution
                ->select(DB::raw('JSON_UNQUOTE(JSON_EXTRACT(asset_metadata.value_json, "$")) as value'), DB::raw('COUNT(*) as count'))
                ->groupBy('value')
                ->get();

            $usageRightsDistribution = $distribution->map(function ($item) {
                return [
                    'value' => $item->value,
                    'count' => $item->count,
                ];
            })->toArray();
        }

        return [
            'expired_count' => $expiredCount,
            'expiring_30_days' => $expiring30,
            'expiring_60_days' => $expiring60,
            'expiring_90_days' => $expiring90,
            'usage_rights_distribution' => $usageRightsDistribution,
        ];
    }

    /**
     * Get governance and permission gaps.
     *
     * @param int $tenantId
     * @param int|null $brandId
     * @param int|null $categoryId
     * @return array
     */
    protected function getGovernanceGaps(
        int $tenantId,
        ?int $brandId,
        ?int $categoryId
    ): array {
        // This would require logging permission checks
        // For now, return placeholder structure
        return [
            'fields_visible_but_non_editable' => [], // Placeholder
            'frequently_blocked_fields' => [], // Placeholder
            'bulk_operations_skipped' => 0, // Placeholder
        ];
    }
}
