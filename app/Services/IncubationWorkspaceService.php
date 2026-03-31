<?php

namespace App\Services;

use App\Models\Tenant;

class IncubationWorkspaceService
{
    /**
     * Hard lock: incubated client past deadline, ownership not yet transferred.
     * No grace period in v1 (see roadmap for optional grace).
     */
    public function isWorkspaceLocked(Tenant $tenant): bool
    {
        if (! $tenant->incubated_by_agency_id) {
            return false;
        }

        if ($tenant->hasCompletedOwnershipTransfer()) {
            return false;
        }

        if (! $tenant->incubation_expires_at) {
            return false;
        }

        return now()->isAfter($tenant->incubation_expires_at);
    }

    public function lockReasonMessage(): string
    {
        return 'This workspace’s incubation window has ended. Complete ownership transfer or contact support for an extension.';
    }
}
