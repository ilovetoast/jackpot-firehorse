<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantModule;

/**
 * Shared Inertia / API payload for Creator module entitlement display.
 */
final class CreatorModuleStatusService
{
    public function __construct(
        private FeatureGate $featureGate
    ) {}

    /**
     * @return array{enabled: bool, status: string|null, expires_at: string|null}
     */
    public function sharedPayload(?Tenant $tenant): array
    {
        if ($tenant === null) {
            return [
                'enabled' => false,
                'status' => null,
                'expires_at' => null,
            ];
        }

        $enabled = $this->featureGate->creatorModuleEnabled($tenant);
        $row = TenantModule::query()
            ->where('tenant_id', $tenant->id)
            ->where('module_key', TenantModule::KEY_CREATOR)
            ->first();

        if ($row === null) {
            return [
                'enabled' => false,
                'status' => null,
                'expires_at' => null,
            ];
        }

        $expiresAt = $row->expires_at;
        $expiresIso = $expiresAt?->toIso8601String();

        return [
            'enabled' => $enabled,
            'status' => $this->resolveDisplayStatus($row, $enabled),
            'expires_at' => $expiresIso,
        ];
    }

    private function resolveDisplayStatus(TenantModule $row, bool $enabled): ?string
    {
        if (in_array($row->status, ['cancelled', 'expired'], true)) {
            return $row->status;
        }

        if (in_array($row->status, ['active', 'trial'], true)) {
            return $enabled ? $row->status : 'expired';
        }

        return $row->status;
    }
}
