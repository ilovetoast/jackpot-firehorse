<?php

namespace App\Services;

use App\Enums\MetricType;
use App\Enums\ViewType;
use App\Models\Asset;
use App\Models\AssetMetric;
use App\Models\MetricAggregate;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * AssetMetricsService
 * 
 * Service for recording and querying asset metrics.
 * Handles individual metric events with deduplication for views.
 * Provides query methods for both individual events and aggregates.
 */
class AssetMetricsService
{
    /**
     * Default deduplication window in minutes for view metrics.
     */
    protected const DEFAULT_DEDUPLICATION_WINDOW_MINUTES = 5;

    /**
     * Record a metric event for an asset.
     * 
     * For VIEW metrics, deduplicates views within the time window.
     * For DOWNLOAD metrics, always records the event.
     * 
     * @param Asset $asset The asset to record the metric for
     * @param MetricType $type The type of metric (download, view)
     * @param ViewType|null $viewType The view type (drawer, large_view) - required for VIEW metrics
     * @param array|null $metadata Additional metadata to store
     * @param User|null $user The user that triggered the metric (null for guest)
     * @return AssetMetric|null Returns the created metric, or existing metric if deduplicated, or null on failure
     */
    public function recordMetric(
        Asset $asset,
        MetricType $type,
        ?ViewType $viewType = null,
        ?array $metadata = null,
        ?User $user = null
    ): ?AssetMetric {
        try {
            // For VIEW metrics, validate view_type is provided
            if ($type === MetricType::VIEW && !$viewType) {
                Log::warning('[AssetMetricsService] View type required for VIEW metrics', [
                    'asset_id' => $asset->id,
                ]);
                return null;
            }

            // For VIEW metrics, check deduplication
            if ($type === MetricType::VIEW && $viewType && $user) {
                if (!$this->shouldRecordView($asset, $user, $viewType)) {
                    // View was deduplicated - return null to indicate no new record
                    Log::debug('[AssetMetricsService] View metric deduplicated', [
                        'asset_id' => $asset->id,
                        'user_id' => $user->id,
                        'view_type' => $viewType->value,
                    ]);
                    return null;
                }
            }

            // Resolve user if not provided (from authenticated user)
            if (!$user && auth()->check()) {
                $user = auth()->user();
            }

            // Capture request context
            $ipAddress = $this->getIpAddress();
            $userAgent = $this->getUserAgent();

            // Create the metric record
            $metric = AssetMetric::create([
                'tenant_id' => $asset->tenant_id,
                'brand_id' => $asset->brand_id,
                'asset_id' => $asset->id,
                'user_id' => $user?->id,
                'metric_type' => $type,
                'view_type' => $viewType?->value,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'metadata' => $metadata,
                'created_at' => now(),
            ]);

            Log::debug('[AssetMetricsService] Metric recorded', [
                'metric_id' => $metric->id,
                'asset_id' => $asset->id,
                'metric_type' => $type->value,
                'view_type' => $viewType?->value,
                'user_id' => $user?->id,
            ]);

            return $metric;
        } catch (\Exception $e) {
            // Metric recording must never throw - log and return null
            Log::error('[AssetMetricsService] Failed to record metric', [
                'asset_id' => $asset->id,
                'metric_type' => $type->value,
                'view_type' => $viewType?->value,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Check if a view should be recorded (deduplication logic).
     * 
     * Returns false if a view of the same type was recorded for this asset/user
     * within the deduplication window.
     * 
     * @param Asset $asset
     * @param User $user
     * @param ViewType $viewType
     * @param int $windowMinutes Deduplication window in minutes
     * @return bool True if view should be recorded, false if it should be deduplicated
     */
    protected function shouldRecordView(
        Asset $asset,
        User $user,
        ViewType $viewType,
        int $windowMinutes = self::DEFAULT_DEDUPLICATION_WINDOW_MINUTES
    ): bool {
        $windowStart = now()->subMinutes($windowMinutes);

        // Check if there's a recent view of this type for this asset/user
        $recentView = AssetMetric::where('asset_id', $asset->id)
            ->where('user_id', $user->id)
            ->where('metric_type', MetricType::VIEW->value)
            ->where('view_type', $viewType->value)
            ->where('created_at', '>=', $windowStart)
            ->exists();

        return !$recentView;
    }

    /**
     * Get individual metric records for an asset.
     * 
     * @param Asset $asset
     * @param MetricType|null $type Filter by metric type
     * @param Carbon|null $startDate Filter by start date
     * @param Carbon|null $endDate Filter by end date
     * @return Collection<AssetMetric>
     */
    public function getMetrics(
        Asset $asset,
        ?MetricType $type = null,
        ?Carbon $startDate = null,
        ?Carbon $endDate = null
    ): Collection {
        $query = AssetMetric::forAsset($asset->id);

        if ($type) {
            $query->ofType($type);
        }

        if ($startDate || $endDate) {
            $query->inDateRange($startDate, $endDate);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    /**
     * Get aggregated metrics for an asset.
     * 
     * @param Asset $asset
     * @param MetricType $type
     * @param string $period Period type: 'daily', 'weekly', 'monthly'
     * @param Carbon|null $startDate Filter by start date
     * @return Collection<MetricAggregate>
     */
    public function getAggregates(
        Asset $asset,
        MetricType $type,
        string $period = 'daily',
        ?Carbon $startDate = null
    ): Collection {
        $query = MetricAggregate::forAsset($asset->id)
            ->ofType($type)
            ->forPeriod($period);

        if ($startDate) {
            $query->inDateRange($startDate);
        }

        return $query->orderBy('period_start', 'desc')->get();
    }

    /**
     * Get total count for a metric type.
     * 
     * Uses aggregate if available, otherwise falls back to counting individual records.
     * 
     * @param Asset $asset
     * @param MetricType $type
     * @return int Total count
     */
    public function getTotalCount(Asset $asset, MetricType $type): int
    {
        // Try to get from daily aggregate (sum of all daily counts)
        $dailyAggregates = MetricAggregate::forAsset($asset->id)
            ->ofType($type)
            ->forPeriod('daily')
            ->sum('count');

        if ($dailyAggregates > 0) {
            // Also check for any individual metrics not yet aggregated
            // (metrics created today that haven't been aggregated yet)
            $todayStart = Carbon::today();
            $todayMetrics = AssetMetric::forAsset($asset->id)
                ->ofType($type)
                ->where('created_at', '>=', $todayStart)
                ->count();

            return $dailyAggregates + $todayMetrics;
        }

        // Fall back to counting individual records if no aggregates exist
        return AssetMetric::forAsset($asset->id)
            ->ofType($type)
            ->count();
    }

    /**
     * Get IP address from request context.
     * Safe to call from any context (HTTP, jobs, commands).
     * 
     * @return string|null
     */
    protected function getIpAddress(): ?string
    {
        try {
            $request = request();
            if ($request) {
                return $request->ip();
            }
        } catch (\Exception $e) {
            // Not in request context
        }

        return null;
    }

    /**
     * Get user agent from request context.
     * Safe to call from any context (HTTP, jobs, commands).
     * 
     * @return string|null
     */
    protected function getUserAgent(): ?string
    {
        try {
            $request = request();
            if ($request) {
                return $request->userAgent();
            }
        } catch (\Exception $e) {
            // Not in request context
        }

        return null;
    }

    /**
     * Check if view deduplication should be applied.
     * 
     * @param Asset $asset
     * @param User $user
     * @param ViewType $viewType
     * @param int $windowMinutes
     * @return bool
     */
    public function deduplicateViews(
        Asset $asset,
        User $user,
        ViewType $viewType,
        int $windowMinutes = self::DEFAULT_DEDUPLICATION_WINDOW_MINUTES
    ): bool {
        return $this->shouldRecordView($asset, $user, $viewType, $windowMinutes);
    }
}
