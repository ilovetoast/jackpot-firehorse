<?php

namespace App\Services;

use App\Enums\EventType;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\User;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Admin / ops path: grant Creator module with mandatory expiry (enforced by {@see TenantModule} saving rules).
 */
final class GrantCreatorModuleToTenant
{
    /**
     * @param  'active'|'trial'  $status
     */
    public function grant(
        Tenant $tenant,
        ?DateTimeInterface $expiresAt,
        ?User $grantedBy = null,
        string $status = 'active',
    ): TenantModule {
        if ($expiresAt === null) {
            throw new InvalidArgumentException('Admin grant requires expires_at.');
        }

        if (! in_array($status, ['active', 'trial'], true)) {
            throw new InvalidArgumentException('Grant status must be active or trial.');
        }

        /** @var TenantModule $module */
        $module = TenantModule::query()->updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'module_key' => TenantModule::KEY_CREATOR,
            ],
            [
                'status' => $status,
                'expires_at' => $expiresAt,
                'granted_by_admin' => true,
            ]
        );

        ActivityRecorder::record(
            tenant: $tenant,
            eventType: EventType::CREATOR_MODULE_ADMIN_GRANTED,
            subject: $module,
            actor: $grantedBy,
            brand: null,
            metadata: [
                'expires_at' => $expiresAt->format(DATE_ATOM),
                'status' => $status,
            ]
        );

        return $module->fresh() ?? $module;
    }
}
