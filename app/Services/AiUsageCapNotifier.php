<?php

namespace App\Services;

use App\Mail\AiMonthlyUsageCapReachedMail;
use App\Models\AIAgentRun;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends at most one system email per tenant per calendar month when a monthly AI cap error is recorded.
 * Skips tenants in agency incubation (incubated_by_agency_id set).
 */
class AiUsageCapNotifier
{
    public function maybeNotifyOwnerFromFailedAgentRun(AIAgentRun $run, string $message): void
    {
        $lower = strtolower($message);
        if (! str_contains($lower, 'cap exceeded') || ! str_contains($lower, 'monthly ai')) {
            return;
        }

        $tenantId = $run->tenant_id;
        if (! $tenantId) {
            return;
        }

        $tenant = Tenant::query()->find($tenantId);
        if (! $tenant) {
            return;
        }

        if ($tenant->incubated_by_agency_id) {
            Log::info('[AiUsageCapNotifier] Skipping owner email — tenant in incubation', [
                'tenant_id' => $tenant->id,
                'ai_agent_run_id' => $run->id,
            ]);

            return;
        }

        $cacheKey = 'ai_usage_cap_owner_email:'.$tenant->id.':'.now()->format('Y-m');
        if (Cache::has($cacheKey)) {
            return;
        }

        $owner = $tenant->owner();
        if (! $owner || ! $owner->email) {
            Log::warning('[AiUsageCapNotifier] No owner email for tenant', ['tenant_id' => $tenant->id]);

            return;
        }

        try {
            Mail::to($owner->email)->send(new AiMonthlyUsageCapReachedMail($tenant, $message, $run->id));
            Cache::put($cacheKey, 1, now()->endOfMonth());
        } catch (\Throwable $e) {
            Log::error('[AiUsageCapNotifier] Failed to send cap email', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
