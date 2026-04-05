<?php

namespace App\Services;

use App\Models\Tenant;

/**
 * UX copy for Creator (Prostaff) module entitlement messaging.
 */
final class CreatorModuleMessageService
{
    public function getExpiredMessage(Tenant $tenant): string
    {
        return 'Creator Module is no longer active. Reactivate to continue managing your creators.';
    }
}
