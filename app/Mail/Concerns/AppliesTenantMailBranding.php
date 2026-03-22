<?php

namespace App\Mail\Concerns;

use App\Models\Tenant;
use App\Support\TenantMailBranding;

trait AppliesTenantMailBranding
{
    /**
     * Call at the start of envelope() for mailables tied to a tenant.
     * Sets staging From name/address and optional Reply-To when tenant email is set.
     */
    protected function applyTenantMailBranding(?Tenant $tenant): void
    {
        TenantMailBranding::apply($tenant);

        if ($tenant && filled($tenant->email)) {
            $this->replyTo($tenant->email, $tenant->name);
        }
    }
}
