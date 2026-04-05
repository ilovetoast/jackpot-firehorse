<?php

namespace App\Observers;

use App\Enums\EventType;
use App\Models\TenantModule;
use App\Services\ActivityRecorder;

/**
 * Audit trail for Creator module lifecycle (Stripe wiring can reuse the same row updates later).
 */
class TenantModuleObserver
{
    public function created(TenantModule $tenantModule): void
    {
        if (! $this->isCreatorModule($tenantModule)) {
            return;
        }

        if (! $this->rowLooksEntitled($tenantModule)) {
            return;
        }

        ActivityRecorder::system(
            tenant: $tenantModule->tenant_id,
            eventType: EventType::CREATOR_MODULE_ACTIVATED,
            subject: $tenantModule,
            metadata: [
                'status' => $tenantModule->status,
                'expires_at' => $tenantModule->expires_at?->toIso8601String(),
            ]
        );
    }

    public function updated(TenantModule $tenantModule): void
    {
        if (! $this->isCreatorModule($tenantModule)) {
            return;
        }

        if (! $tenantModule->wasChanged('status')) {
            return;
        }

        $previous = (string) $tenantModule->getOriginal('status');
        $current = (string) $tenantModule->status;

        if ($current === 'expired' && $previous !== 'expired') {
            ActivityRecorder::system(
                tenant: $tenantModule->tenant_id,
                eventType: EventType::CREATOR_MODULE_EXPIRED,
                subject: $tenantModule,
                metadata: ['previous_status' => $previous]
            );

            return;
        }

        if ($current === 'cancelled' && $previous !== 'cancelled') {
            ActivityRecorder::system(
                tenant: $tenantModule->tenant_id,
                eventType: EventType::CREATOR_MODULE_CANCELLED,
                subject: $tenantModule,
                metadata: ['previous_status' => $previous]
            );

            return;
        }

        if (in_array($current, ['active', 'trial'], true)
            && in_array($previous, ['expired', 'cancelled'], true)
            && $this->rowLooksEntitled($tenantModule)) {
            ActivityRecorder::system(
                tenant: $tenantModule->tenant_id,
                eventType: EventType::CREATOR_MODULE_ACTIVATED,
                subject: $tenantModule,
                metadata: [
                    'previous_status' => $previous,
                    'status' => $current,
                ]
            );
        }
    }

    private function isCreatorModule(TenantModule $tenantModule): bool
    {
        return $tenantModule->module_key === TenantModule::KEY_CREATOR;
    }

    private function rowLooksEntitled(TenantModule $tenantModule): bool
    {
        if (! in_array($tenantModule->status, ['active', 'trial'], true)) {
            return false;
        }

        if ($tenantModule->granted_by_admin && $tenantModule->expires_at === null) {
            return false;
        }

        if ($tenantModule->expires_at !== null && $tenantModule->expires_at->lte(now())) {
            return false;
        }

        return true;
    }
}
