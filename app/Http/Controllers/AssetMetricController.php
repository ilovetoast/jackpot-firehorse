<?php

namespace App\Http\Controllers;

use App\Enums\MetricType;
use App\Enums\ViewType;
use App\Models\Asset;
use App\Services\AssetMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * AssetMetricController
 * 
 * Handles metric tracking and querying for assets.
 */
class AssetMetricController extends Controller
{
    public function __construct(
        protected AssetMetricsService $metricsService
    ) {
    }

    /**
     * Track a metric event for an asset.
     * 
     * POST /api/assets/{asset}/metrics/track
     * 
     * @param Request $request
     * @param Asset $asset
     * @return JsonResponse
     */
    public function track(Request $request, Asset $asset): JsonResponse
    {
        // Authorize user can view asset
        $this->authorize('view', $asset);

        $validated = $request->validate([
            'type' => 'required|string|in:download,view',
            'view_type' => 'nullable|string|in:drawer,large_view',
            'metadata' => 'nullable|array',
        ]);

        try {
            $metricType = MetricType::from($validated['type']);
            $viewType = isset($validated['view_type']) 
                ? ViewType::from($validated['view_type']) 
                : null;

            // For VIEW metrics, view_type is required
            if ($metricType === MetricType::VIEW && !$viewType) {
                return response()->json([
                    'success' => false,
                    'error' => 'view_type is required for view metrics',
                ], 422);
            }

            $user = $request->user();
            $metadata = $validated['metadata'] ?? null;

            $metric = $this->metricsService->recordMetric(
                asset: $asset,
                type: $metricType,
                viewType: $viewType,
                metadata: $metadata,
                user: $user
            );

            // If metric is null, it was deduplicated (for views)
            if ($metric === null) {
                return response()->json([
                    'success' => true,
                    'deduplicated' => true,
                    'message' => 'Metric was deduplicated (view within time window)',
                ], 200);
            }

            return response()->json([
                'success' => true,
                'metric' => [
                    'id' => $metric->id,
                    'type' => $metric->metric_type->value,
                    'view_type' => $metric->view_type?->value,
                    'created_at' => $metric->created_at->toISOString(),
                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('[AssetMetricController] Failed to track metric', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to track metric',
            ], 500);
        }
    }

    /**
     * Get metrics for an asset.
     * 
     * GET /api/assets/{asset}/metrics
     * 
     * @param Request $request
     * @param Asset $asset
     * @return JsonResponse
     */
    public function index(Request $request, Asset $asset): JsonResponse
    {
        // Authorize user can view asset
        $this->authorize('view', $asset);

        $validated = $request->validate([
            'type' => 'nullable|string|in:download,view',
            'period' => 'nullable|string|in:daily,weekly,monthly',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        try {
            $metricType = isset($validated['type']) 
                ? MetricType::from($validated['type']) 
                : null;
            
            $period = $validated['period'] ?? 'daily';
            $startDate = isset($validated['start_date']) 
                ? Carbon::parse($validated['start_date']) 
                : null;
            $endDate = isset($validated['end_date']) 
                ? Carbon::parse($validated['end_date']) 
                : null;

            // If period is specified, return aggregates
            if ($metricType && $period) {
                $aggregates = $this->metricsService->getAggregates(
                    asset: $asset,
                    type: $metricType,
                    period: $period,
                    startDate: $startDate
                );

                return response()->json([
                    'success' => true,
                    'type' => $metricType->value,
                    'period' => $period,
                    'aggregates' => $aggregates->map(function ($aggregate) {
                        return [
                            'period_start' => $aggregate->period_start->format('Y-m-d'),
                            'count' => $aggregate->count,
                            'unique_users' => $aggregate->unique_users,
                        ];
                    }),
                ], 200);
            }

            // Otherwise, return individual metrics
            $metrics = $this->metricsService->getMetrics(
                asset: $asset,
                type: $metricType,
                startDate: $startDate,
                endDate: $endDate
            );

            return response()->json([
                'success' => true,
                'metrics' => $metrics->map(function ($metric) {
                    return [
                        'id' => $metric->id,
                        'type' => $metric->metric_type->value,
                        'view_type' => $metric->view_type?->value,
                        'user_id' => $metric->user_id,
                        'created_at' => $metric->created_at->toISOString(),
                    ];
                }),
            ], 200);
        } catch (\Exception $e) {
            Log::error('[AssetMetricController] Failed to get metrics', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to get metrics',
            ], 500);
        }
    }

    /**
     * Get download metrics for an asset.
     * 
     * GET /api/assets/{asset}/metrics/downloads
     * 
     * @param Request $request
     * @param Asset $asset
     * @return JsonResponse
     */
    public function downloads(Request $request, Asset $asset): JsonResponse
    {
        // Authorize user can view asset
        $this->authorize('view', $asset);

        try {
            $totalCount = $this->metricsService->getTotalCount($asset, MetricType::DOWNLOAD);

            return response()->json([
                'success' => true,
                'type' => 'download',
                'total_count' => $totalCount,
            ], 200);
        } catch (\Exception $e) {
            Log::error('[AssetMetricController] Failed to get download metrics', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to get download metrics',
            ], 500);
        }
    }

    /**
     * Get view metrics for an asset.
     * 
     * GET /api/assets/{asset}/metrics/views
     * 
     * @param Request $request
     * @param Asset $asset
     * @return JsonResponse
     */
    public function views(Request $request, Asset $asset): JsonResponse
    {
        // Authorize user can view asset
        $this->authorize('view', $asset);

        try {
            $totalCount = $this->metricsService->getTotalCount($asset, MetricType::VIEW);

            return response()->json([
                'success' => true,
                'type' => 'view',
                'total_count' => $totalCount,
            ], 200);
        } catch (\Exception $e) {
            Log::error('[AssetMetricController] Failed to get view metrics', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to get view metrics',
            ], 500);
        }
    }
}
