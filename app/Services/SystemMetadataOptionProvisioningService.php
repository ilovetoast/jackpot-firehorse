<?php

namespace App\Services;

use App\Models\MetadataOption;
use App\Models\MetadataOptionVisibility;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * When new system metadata_options are created, existing tenants do not see them until they opt in
 * (hybrid propagation). New tenants have no tenant-level hide row, so they see all current options.
 */
class SystemMetadataOptionProvisioningService
{
    public const PROVISION_SOURCE = 'system_seed';

    public function hideNewSystemOptionForExistingTenants(MetadataOption $option): void
    {
        if (! $option->is_system) {
            return;
        }

        foreach (Tenant::query()->pluck('id') as $tenantId) {
            MetadataOptionVisibility::firstOrCreate(
                [
                    'metadata_option_id' => $option->id,
                    'tenant_id' => $tenantId,
                    'brand_id' => null,
                    'category_id' => null,
                ],
                [
                    'is_hidden' => true,
                    'provision_source' => self::PROVISION_SOURCE,
                ]
            );
        }
    }

    /**
     * Remove auto-provisioned hides so tenants see platform values in pickers.
     *
     * @return int Rows deleted
     */
    public function revealSystemSeededOptionHidesForTenant(int $tenantId): int
    {
        return DB::table('metadata_option_visibility')
            ->where('tenant_id', $tenantId)
            ->whereNull('brand_id')
            ->whereNull('category_id')
            ->where('provision_source', self::PROVISION_SOURCE)
            ->delete();
    }

    public function countPendingSystemSeededHides(int $tenantId): int
    {
        return DB::table('metadata_option_visibility')
            ->where('tenant_id', $tenantId)
            ->whereNull('brand_id')
            ->whereNull('category_id')
            ->where('provision_source', self::PROVISION_SOURCE)
            ->where('is_hidden', true)
            ->count();
    }
}
