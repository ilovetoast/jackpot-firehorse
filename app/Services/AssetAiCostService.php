<?php

namespace App\Services;

use App\Enums\AITaskType;
use App\Models\AIAgentRun;

/**
 * Aggregates AI spend from {@see AIAgentRun} for an asset (tenant/brand scoped by caller).
 */
class AssetAiCostService
{
    public function getTotalCostUsd(string $assetId): float
    {
        $sum = AIAgentRun::query()
            ->where('status', 'success')
            ->where(function ($q) use ($assetId) {
                $q->where(function ($q2) use ($assetId) {
                    $q2->where('entity_type', 'asset')->where('entity_id', $assetId);
                })->orWhereRaw('JSON_UNQUOTE(JSON_EXTRACT(metadata, ?)) = ?', ['$.asset_id', $assetId]);
            })
            ->sum('estimated_cost');

        return round((float) $sum, 6);
    }

    /**
     * Video AI only (vision + transcription), successful runs for an asset this month.
     *
     * @return array{cost_usd: float, jobs_count: int}
     */
    public function getVideoInsightsAggregateForAsset(string $assetId): array
    {
        $monthStart = now()->startOfMonth()->toDateTimeString();

        $q = AIAgentRun::query()
            ->where('task_type', AITaskType::VIDEO_INSIGHTS)
            ->where('status', 'success')
            ->where('started_at', '>=', $monthStart)
            ->where(function ($q2) use ($assetId) {
                $q2->where(function ($q3) use ($assetId) {
                    $q3->where('entity_type', 'asset')->where('entity_id', $assetId);
                })->orWhereRaw('JSON_UNQUOTE(JSON_EXTRACT(metadata, ?)) = ?', ['$.asset_id', $assetId]);
            });

        return [
            'cost_usd' => round((float) (clone $q)->sum('estimated_cost'), 6),
            'jobs_count' => (int) (clone $q)->count(),
        ];
    }

    /**
     * Tenant-wide video AI for dashboards (calendar month).
     *
     * @return array{cost_usd: float, jobs_count: float, minutes_billed: float}
     */
    public function getTenantVideoAiAggregate(int $tenantId): array
    {
        $monthStart = now()->startOfMonth()->toDateTimeString();

        $rows = AIAgentRun::query()
            ->where('tenant_id', $tenantId)
            ->where('task_type', AITaskType::VIDEO_INSIGHTS)
            ->where('status', 'success')
            ->where('started_at', '>=', $monthStart)
            ->get(['estimated_cost', 'metadata']);

        $cost = 0.0;
        $minutes = 0.0;
        foreach ($rows as $row) {
            $cost += (float) ($row->estimated_cost ?? 0);
            $meta = $row->metadata ?? [];
            $minutes += (float) ($meta['billable_minutes'] ?? 0);
        }

        return [
            'cost_usd' => round($cost, 6),
            'jobs_count' => (float) $rows->count(),
            'minutes_billed' => round($minutes, 4),
        ];
    }
}
