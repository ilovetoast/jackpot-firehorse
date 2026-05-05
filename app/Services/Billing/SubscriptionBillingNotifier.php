<?php

namespace App\Services\Billing;

use App\Mail\PlanChangedTenant;
use App\Mail\SubscriptionCancelScheduledTenant;
use App\Mail\SubscriptionEndedTenant;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Self-serve SaaS billing emails (subscribe / plan change / cancel / end).
 * Uses {@see PlanChangedTenant} and dedicated mailables; all are system/automated (EmailGate).
 */
class SubscriptionBillingNotifier
{
    public const SELF_SERVE_SOURCE = 'Self-service billing';

    public function notifyPlanChangedAfterSync(Tenant $tenant, string $oldPlanKey, string $newPlanKey): void
    {
        if ($oldPlanKey === $newPlanKey) {
            return;
        }

        $owner = $tenant->owner();
        if (! $owner?->email) {
            Log::info('subscription_billing_email.skipped_no_owner', ['tenant_id' => $tenant->id]);

            return;
        }

        try {
            Mail::to($owner->email)->send(new PlanChangedTenant(
                $tenant,
                $owner,
                $oldPlanKey,
                $newPlanKey,
                'Paid',
                null,
                self::SELF_SERVE_SOURCE
            ));
        } catch (\Throwable $e) {
            Log::error('subscription_billing_email.plan_changed_failed', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function notifyCancellationScheduled(Tenant $tenant, string $planKey, ?Carbon $accessEndsAt): void
    {
        $owner = $tenant->owner();
        if (! $owner?->email) {
            return;
        }

        try {
            Mail::to($owner->email)->send(new SubscriptionCancelScheduledTenant(
                $tenant,
                $owner,
                $planKey,
                $accessEndsAt
            ));
        } catch (\Throwable $e) {
            Log::error('subscription_billing_email.cancel_scheduled_failed', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function notifySubscriptionEnded(Tenant $tenant, string $previousPlanKey): void
    {
        if ($previousPlanKey === 'free') {
            return;
        }

        $owner = $tenant->owner();
        if (! $owner?->email) {
            return;
        }

        try {
            Mail::to($owner->email)->send(new SubscriptionEndedTenant(
                $tenant,
                $owner,
                $previousPlanKey
            ));
        } catch (\Throwable $e) {
            Log::error('subscription_billing_email.ended_failed', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
