<?php

namespace App\Services;

use App\Enums\ZipStatus;
use App\Models\Download;
use Illuminate\Support\Facades\DB;

/**
 * Phase D5 — Download Metrics (read-only)
 *
 * Aggregation helpers for:
 * - Total bytes stored per tenant (artifact still in storage)
 * - Total bytes deleted per tenant (artifact cleaned up)
 * - Average ZIP size
 * - Average retention duration
 * - Failure rates (builds, cleanups)
 *
 * For profitability analysis, infra forecasting, tenant-level cost insights.
 * Does NOT affect plan limits. Not surfaced in UI yet.
 */
class DownloadMetricsService
{
    /**
     * Total bytes of download artifacts currently in storage for a tenant.
     */
    public function totalBytesStoredPerTenant(string|int $tenantId): int
    {
        return (int) Download::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('zip_path')
            ->whereNull('zip_deleted_at')
            ->where('zip_status', ZipStatus::READY)
            ->sum('zip_size_bytes');
    }

    /**
     * Total bytes of download artifacts that have been deleted (cleaned up) for a tenant.
     */
    public function totalBytesDeletedPerTenant(string|int $tenantId): int
    {
        return (int) Download::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('zip_deleted_at')
            ->sum('zip_size_bytes');
    }

    /**
     * Average ZIP size in bytes (across all downloads that have/had a ZIP).
     */
    public function averageZipSize(?string|int $tenantId = null): float
    {
        $query = Download::query()->whereNotNull('zip_size_bytes')->where('zip_size_bytes', '>', 0);
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }
        $avg = $query->avg('zip_size_bytes');

        return round((float) $avg, 2);
    }

    /**
     * Average retention duration in seconds (created_at → zip_deleted_at) for cleaned downloads.
     */
    public function averageRetentionDurationSeconds(?string|int $tenantId = null): ?float
    {
        $query = Download::query()->whereNotNull('zip_deleted_at');
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            $result = (clone $query)->selectRaw('AVG(TIMESTAMPDIFF(SECOND, created_at, zip_deleted_at)) as avg_seconds')->value('avg_seconds');
        } else {
            $result = (clone $query)->selectRaw('AVG((julianday(zip_deleted_at) - julianday(created_at)) * 86400) as avg_seconds')->value('avg_seconds');
        }

        return $result !== null ? round((float) $result, 2) : null;
    }

    /**
     * Failure rate for ZIP builds: proportion of downloads with zip_status = FAILED.
     * Returns 0–1 (e.g. 0.05 = 5%).
     */
    public function failureRateBuilds(?string|int $tenantId = null): float
    {
        $query = Download::query();
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }
        $total = $query->count();
        if ($total === 0) {
            return 0.0;
        }
        $failed = (clone $query)->where('zip_status', ZipStatus::FAILED)->count();

        return round($failed / $total, 4);
    }

    /**
     * Failure rate for cleanups: proportion of downloads that had cleanup attempted (zip_deleted_at or cleanup_failed_at set)
     * and ended with cleanup_failed_at set.
     * Returns 0–1.
     */
    public function failureRateCleanups(?string|int $tenantId = null): float
    {
        $query = Download::query()
            ->where(function ($q) {
                $q->whereNotNull('zip_deleted_at')->orWhereNotNull('cleanup_failed_at');
            });
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }
        $attempted = $query->count();
        if ($attempted === 0) {
            return 0.0;
        }
        $failed = (clone $query)->whereNotNull('cleanup_failed_at')->count();

        return round($failed / $attempted, 4);
    }
}
