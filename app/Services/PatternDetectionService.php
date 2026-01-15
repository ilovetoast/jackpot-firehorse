<?php

namespace App\Services;

use App\Models\AssetEventAggregate;
use App\Models\DetectionRule;
use App\Models\DownloadEventAggregate;
use App\Models\EventAggregate;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 🔒 Phase 4 Step 3 — Pattern Detection Service
 * 
 * Consumes aggregates from locked phases only.
 * Must not modify event producers or aggregation logic.
 * 
 * PatternDetectionService
 * 
 * Evaluates declarative detection rules against event aggregates.
 * Returns matched rule results for further processing (alerts, AI analysis, etc.).
 * 
 * NO SIDE EFFECTS — read-only evaluation.
 * NO ALERTING — returns results only.
 */
class PatternDetectionService
{
    /**
     * Evaluate all enabled detection rules.
     * 
     * @param Carbon|null $asOfTime Time to evaluate rules at (defaults to now)
     * @return Collection<array{
     *   rule_id: int,
     *   rule_name: string,
     *   scope: string,
     *   subject_id: string|null,
     *   severity: string,
     *   observed_count: int,
     *   threshold_count: int,
     *   window_minutes: int,
     *   metadata_summary: array
     * }>
     */
    public function evaluateAllRules(?Carbon $asOfTime = null): Collection
    {
        $asOfTime = $asOfTime ?: Carbon::now();

        Log::debug('[PatternDetectionService] Evaluating all enabled rules', [
            'as_of_time' => $asOfTime->toIso8601String(),
        ]);

        $rules = DetectionRule::enabled()->get();
        $results = collect();

        foreach ($rules as $rule) {
            try {
                $matches = $this->evaluateRule($rule, $asOfTime);
                $results = $results->merge($matches);
            } catch (\Throwable $e) {
                Log::error('[PatternDetectionService] Error evaluating rule', [
                    'rule_id' => $rule->id,
                    'rule_name' => $rule->name,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                // Continue with other rules
            }
        }

        Log::debug('[PatternDetectionService] Rule evaluation completed', [
            'rules_evaluated' => $rules->count(),
            'matches_found' => $results->count(),
        ]);

        return $results;
    }

    /**
     * Evaluate a specific rule against aggregates.
     * 
     * @param DetectionRule $rule
     * @param Carbon|null $asOfTime
     * @return Collection<array{rule_id: int, rule_name: string, scope: string, subject_id: string|null, severity: string, observed_count: int, threshold_count: int, window_minutes: int, metadata_summary: array}>
     */
    public function evaluateRule(DetectionRule $rule, ?Carbon $asOfTime = null): Collection
    {
        $asOfTime = $asOfTime ?: Carbon::now();

        $windowStart = $asOfTime->copy()->subMinutes($rule->threshold_window_minutes);

        $matches = collect();

        switch ($rule->scope) {
            case 'global':
                $matches = $this->evaluateGlobalRule($rule, $windowStart, $asOfTime);
                break;

            case 'tenant':
                $matches = $this->evaluateTenantRule($rule, $windowStart, $asOfTime);
                break;

            case 'asset':
                $matches = $this->evaluateAssetRule($rule, $windowStart, $asOfTime);
                break;

            case 'download':
                $matches = $this->evaluateDownloadRule($rule, $windowStart, $asOfTime);
                break;

            default:
                Log::warning('[PatternDetectionService] Unknown rule scope', [
                    'rule_id' => $rule->id,
                    'scope' => $rule->scope,
                ]);
        }

        return $matches;
    }

    /**
     * Evaluate a global-scope rule.
     * 
     * @param DetectionRule $rule
     * @param Carbon $windowStart
     * @param Carbon $windowEnd
     * @return Collection
     */
    protected function evaluateGlobalRule(DetectionRule $rule, Carbon $windowStart, Carbon $windowEnd): Collection
    {
        // Aggregate across all tenants for this event type
        $aggregates = EventAggregate::where('event_type', $rule->event_type)
            ->whereBetween('bucket_start_at', [$windowStart, $windowEnd])
            ->get();

        // Apply metadata filters if present
        $aggregates = $this->applyMetadataFilters($aggregates, $rule->metadata_filters);

        // Sum counts across all aggregates
        $totalCount = $aggregates->sum('count');

        if ($this->matchesThreshold($totalCount, $rule->threshold_count, $rule->comparison)) {
            return collect([$this->buildMatchResult($rule, 'global', null, $totalCount, $aggregates)]);
        }

        return collect();
    }

    /**
     * Evaluate a tenant-scope rule.
     * 
     * @param DetectionRule $rule
     * @param Carbon $windowStart
     * @param Carbon $windowEnd
     * @return Collection
     */
    protected function evaluateTenantRule(DetectionRule $rule, Carbon $windowStart, Carbon $windowEnd): Collection
    {
        $matches = collect();

        // Group aggregates by tenant
        $tenantAggregates = EventAggregate::where('event_type', $rule->event_type)
            ->whereBetween('bucket_start_at', [$windowStart, $windowEnd])
            ->get()
            ->groupBy('tenant_id');

        foreach ($tenantAggregates as $tenantId => $aggregates) {
            // Apply metadata filters if present
            $filteredAggregates = $this->applyMetadataFilters($aggregates, $rule->metadata_filters);

            // Sum counts for this tenant
            $tenantCount = $filteredAggregates->sum('count');

            if ($this->matchesThreshold($tenantCount, $rule->threshold_count, $rule->comparison)) {
                $matches->push($this->buildMatchResult($rule, 'tenant', (string)$tenantId, $tenantCount, $filteredAggregates));
            }
        }

        return $matches;
    }

    /**
     * Evaluate an asset-scope rule.
     * 
     * @param DetectionRule $rule
     * @param Carbon $windowStart
     * @param Carbon $windowEnd
     * @return Collection
     */
    protected function evaluateAssetRule(DetectionRule $rule, Carbon $windowStart, Carbon $windowEnd): Collection
    {
        $matches = collect();

        // Get asset-level aggregates
        $assetAggregates = AssetEventAggregate::where('event_type', $rule->event_type)
            ->whereBetween('bucket_start_at', [$windowStart, $windowEnd])
            ->get()
            ->groupBy('asset_id');

        foreach ($assetAggregates as $assetId => $aggregates) {
            // Apply metadata filters if present
            $filteredAggregates = $this->applyMetadataFilters($aggregates, $rule->metadata_filters);

            // Sum counts for this asset
            $assetCount = $filteredAggregates->sum('count');

            if ($this->matchesThreshold($assetCount, $rule->threshold_count, $rule->comparison)) {
                $matches->push($this->buildMatchResult($rule, 'asset', (string)$assetId, $assetCount, $filteredAggregates));
            }
        }

        return $matches;
    }

    /**
     * Evaluate a download-scope rule.
     * 
     * @param DetectionRule $rule
     * @param Carbon $windowStart
     * @param Carbon $windowEnd
     * @return Collection
     */
    protected function evaluateDownloadRule(DetectionRule $rule, Carbon $windowStart, Carbon $windowEnd): Collection
    {
        $matches = collect();

        // Get download-level aggregates
        $downloadAggregates = DownloadEventAggregate::where('event_type', $rule->event_type)
            ->whereBetween('bucket_start_at', [$windowStart, $windowEnd])
            ->get()
            ->groupBy('download_id');

        foreach ($downloadAggregates as $downloadId => $aggregates) {
            // Apply metadata filters if present
            $filteredAggregates = $this->applyMetadataFilters($aggregates, $rule->metadata_filters);

            // Sum counts for this download
            $downloadCount = $filteredAggregates->sum('count');

            if ($this->matchesThreshold($downloadCount, $rule->threshold_count, $rule->comparison)) {
                $matches->push($this->buildMatchResult($rule, 'download', (string)$downloadId, $downloadCount, $filteredAggregates));
            }
        }

        return $matches;
    }

    /**
     * Apply metadata filters to aggregates.
     * 
     * Filters aggregates based on metadata conditions.
     * Supports filtering by error_code, file_type, context, etc.
     * 
     * @param Collection $aggregates
     * @param array|null $metadataFilters
     * @return Collection
     */
    protected function applyMetadataFilters(Collection $aggregates, ?array $metadataFilters): Collection
    {
        if (empty($metadataFilters)) {
            return $aggregates;
        }

        return $aggregates->filter(function ($aggregate) use ($metadataFilters) {
            $metadata = $aggregate->metadata ?? [];

            foreach ($metadataFilters as $key => $filterValue) {
                // Special handling for error_codes field
                // Error codes are stored as: { "error_codes": { "ERROR_CODE": count } }
                if ($key === 'error_codes') {
                    // Check if error_codes array exists and contains the filter value as a key
                    if (!isset($metadata['error_codes']) || !is_array($metadata['error_codes'])) {
                        return false;
                    }
                    if (!isset($metadata['error_codes'][$filterValue])) {
                        return false;
                    }
                    // Match found in error_codes counts
                    continue;
                }

                // Standard metadata field check
                if (!isset($metadata[$key])) {
                    return false;
                }

                // Handle array values (counts or lists)
                if (is_array($metadata[$key])) {
                    // For count-based arrays (e.g., file_types, contexts), check if key exists
                    if (isset($metadata[$key][$filterValue])) {
                        // Match found in counts
                        continue;
                    }
                    // For list-based arrays, check if value is in array
                    if (in_array($filterValue, $metadata[$key])) {
                        continue;
                    }
                    return false;
                } elseif ($metadata[$key] !== $filterValue) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * Check if observed count matches threshold.
     * 
     * @param int $observedCount
     * @param int $thresholdCount
     * @param string $comparison
     * @return bool
     */
    protected function matchesThreshold(int $observedCount, int $thresholdCount, string $comparison): bool
    {
        switch ($comparison) {
            case 'greater_than':
                return $observedCount > $thresholdCount;

            case 'greater_than_or_equal':
                return $observedCount >= $thresholdCount;

            default:
                Log::warning('[PatternDetectionService] Unknown comparison operator', [
                    'comparison' => $comparison,
                ]);
                return false;
        }
    }

    /**
     * Build match result array.
     * 
     * @param DetectionRule $rule
     * @param string $scope
     * @param string|null $subjectId
     * @param int $observedCount
     * @param Collection $aggregates
     * @return array
     */
    protected function buildMatchResult(
        DetectionRule $rule,
        string $scope,
        ?string $subjectId,
        int $observedCount,
        Collection $aggregates
    ): array {
        // Extract metadata summary from aggregates
        $metadataSummary = $this->extractMetadataSummary($aggregates);

        return [
            'rule_id' => $rule->id,
            'rule_name' => $rule->name,
            'scope' => $scope,
            'subject_id' => $subjectId,
            'severity' => $rule->severity,
            'observed_count' => $observedCount,
            'threshold_count' => $rule->threshold_count,
            'window_minutes' => $rule->threshold_window_minutes,
            'metadata_summary' => $metadataSummary,
        ];
    }

    /**
     * Extract metadata summary from aggregates.
     * 
     * @param Collection $aggregates
     * @return array
     */
    protected function extractMetadataSummary(Collection $aggregates): array
    {
        $summary = [];

        foreach ($aggregates as $aggregate) {
            $metadata = $aggregate->metadata ?? [];

            foreach ($metadata as $key => $value) {
                if (!isset($summary[$key])) {
                    $summary[$key] = [];
                }

                // Merge counts
                if (is_array($value)) {
                    foreach ($value as $subKey => $subValue) {
                        $summary[$key][$subKey] = ($summary[$key][$subKey] ?? 0) + $subValue;
                    }
                } else {
                    $summary[$key][] = $value;
                }
            }
        }

        // Remove duplicates for non-count fields
        foreach ($summary as $key => $value) {
            if (is_array($value) && !empty($value) && !is_numeric(array_keys($value)[0] ?? null)) {
                // This is a count array (key-value pairs), keep as is
                continue;
            } elseif (is_array($value)) {
                // This is a list, remove duplicates
                $summary[$key] = array_unique($value);
            }
        }

        return $summary;
    }
}
