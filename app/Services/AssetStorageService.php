<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Tenant;

class AssetStorageService
{
    /**
     * Calculate total storage used by a tenant.
     * Soft-deleted assets are excluded from storage calculations.
     *
     * @param Tenant $tenant
     * @return int Total storage in bytes
     */
    public function calculateTotalStorage(Tenant $tenant): int
    {
        // Query only non-soft-deleted assets
        return Asset::where('tenant_id', $tenant->id)
            ->sum('size_bytes') ?? 0;
    }

    /**
     * Calculate storage used by a brand.
     * Soft-deleted assets are excluded from storage calculations.
     *
     * @param int $brandId
     * @param int $tenantId
     * @return int Total storage in bytes
     */
    public function calculateBrandStorage(int $brandId, int $tenantId): int
    {
        // Query only non-soft-deleted assets
        return Asset::where('tenant_id', $tenantId)
            ->where('brand_id', $brandId)
            ->sum('size_bytes') ?? 0;
    }

    /**
     * Get storage breakdown by asset status.
     *
     * @param Tenant $tenant
     * @return array
     */
    public function getStorageBreakdown(Tenant $tenant): array
    {
        // Query only non-soft-deleted assets grouped by status
        $breakdown = Asset::where('tenant_id', $tenant->id)
            ->selectRaw('status, SUM(size_bytes) as total_size')
            ->groupBy('status')
            ->pluck('total_size', 'status')
            ->toArray();

        return [
            'total' => array_sum($breakdown),
            'by_status' => $breakdown,
        ];
    }
}
