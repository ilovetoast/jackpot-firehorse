<?php

namespace App\Services;

use App\Enums\EventType;
use App\Mail\BillingTrialExpired;
use App\Mail\BillingCompedExpired;
use App\Mail\PlanChangedTenant;
use App\Mail\PlanChangedAdmin;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;

/**
 * Billing Expiration Service
 * 
 * Handles expiration logic for trial and comped accounts.
 * Enterprise SaaS-grade expiration handling with protections and audit trails.
 * 
 * State Machine:
 * - 'paid': Normal billing (no expiration)
 * - 'trial': Trial period with expiration date → converts to 'paid' or 'free' on expiration
 * - 'comped': Free account with optional expiration → converts to 'free' plan on expiration
 * 
 * Protections:
 * - Grace period support (warnings before expiration)
 * - Prevents expiration if account has active Stripe subscription
 * - Audit logging for all state changes
 * - Notification system for warnings
 * - Can be manually extended or converted to paid
 * 
 * TODO: Add email notifications before expiration
 * TODO: Add grace period logic
 * TODO: Add manual extension capability
 * TODO: Add bulk expiration processing
 */
class BillingExpirationService
{
    public function __construct(
        protected ActivityRecorder $activityRecorder
    ) {
    }

    /**
     * Process expired accounts.
     * 
     * Checks all accounts with billing_status_expires_at in the past
     * and handles expiration based on billing_status.
     * 
     * @param bool $dryRun If true, only logs what would happen without making changes
     * @return array Results with counts and details
     */
    public function processExpiredAccounts(bool $dryRun = false): array
    {
        $now = now();
        $results = [
            'expired_trials' => 0,
            'expired_comped' => 0,
            'skipped' => 0,
            'errors' => 0,
            'details' => [],
        ];

        // Find all accounts with expired billing_status
        // Only check trial and comped accounts (paid accounts don't expire)
        $expiredAccounts = Tenant::whereIn('billing_status', ['trial', 'comped'])
            ->whereNotNull('billing_status_expires_at')
            ->where('billing_status_expires_at', '<=', $now)
            ->get();

        foreach ($expiredAccounts as $tenant) {
            try {
                $result = $this->handleAccountExpiration($tenant, $dryRun);
                
                if ($result['status'] === 'expired') {
                    if ($tenant->billing_status === 'trial') {
                        $results['expired_trials']++;
                    } elseif ($tenant->billing_status === 'comped') {
                        $results['expired_comped']++;
                    }
                } elseif ($result['status'] === 'skipped') {
                    $results['skipped']++;
                } elseif ($result['status'] === 'error') {
                    $results['errors']++;
                }
                
                $results['details'][] = $result;
            } catch (\Exception $e) {
                Log::error('Failed to process expired account', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $results['errors']++;
                $results['details'][] = [
                    'tenant_id' => $tenant->id,
                    'tenant_name' => $tenant->name,
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Handle expiration for a single account.
     * 
     * @param Tenant $tenant
     * @param bool $dryRun
     * @return array
     */
    protected function handleAccountExpiration(Tenant $tenant, bool $dryRun = false): array
    {
        // Protection: Don't expire if account has active Stripe subscription
        // If they upgraded during trial/comped period, they should stay on paid plan
        $activeSubscription = $tenant->subscriptions()
            ->where('name', 'default')
            ->where('stripe_status', 'active')
            ->first();
        
        if ($activeSubscription) {
            // Account upgraded to paid - clear expiration and billing_status
            // Protection: Don't expire accounts that have upgraded to paid
            $previousBillingStatus = $tenant->billing_status;
            $previousExpiration = $tenant->billing_status_expires_at;
            
            if (!$dryRun) {
                $tenant->billing_status = null; // Set to paid
                $tenant->billing_status_expires_at = null;
                $tenant->save();
                
                // Log activity
                $this->activityRecorder->record(
                    tenant: $tenant,
                    eventType: EventType::PLAN_UPDATED,
                    subject: $tenant,
                    description: "Billing status cleared - account upgraded to paid subscription",
                    metadata: [
                        'previous_billing_status' => $previousBillingStatus,
                        'previous_expiration' => $previousExpiration?->toIso8601String(),
                        'reason' => 'upgraded_to_paid',
                        'auto_cleared' => true,
                    ]
                );
            }
            
            return [
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
                'status' => 'skipped',
                'message' => 'Account has active Stripe subscription - upgraded to paid',
                'previous_billing_status' => $previousBillingStatus,
            ];
        }

        // Handle expiration based on billing_status
        if ($tenant->billing_status === 'trial') {
            return $this->handleTrialExpiration($tenant, $dryRun);
        } elseif ($tenant->billing_status === 'comped') {
            return $this->handleCompedExpiration($tenant, $dryRun);
        }

        // Should not reach here, but handle gracefully
        return [
            'tenant_id' => $tenant->id,
            'tenant_name' => $tenant->name,
            'status' => 'skipped',
            'message' => 'Unknown billing_status - skipping',
            'billing_status' => $tenant->billing_status,
        ];
    }

    /**
     * Handle trial expiration.
     * 
     * When trial expires:
     * - If they haven't upgraded: Set plan to 'free', clear billing_status
     * - Clear expiration date
     * - Log activity
     * 
     * TODO: Add grace period (e.g., 7 days) before downgrading
     * TODO: Send expiration notification before downgrade
     * 
     * @param Tenant $tenant
     * @param bool $dryRun
     * @return array
     */
    protected function handleTrialExpiration(Tenant $tenant, bool $dryRun = false): array
    {
        $oldPlan = app(PlanService::class)->getCurrentPlan($tenant);
        $expirationDate = $tenant->billing_status_expires_at;
        $previousBillingStatus = $tenant->billing_status; // Store before clearing
        
        if (!$dryRun) {
            // Set plan to free (clear manual_plan_override or set to free)
            $tenant->manual_plan_override = 'free';
            $tenant->billing_status = null; // Clear trial status (now paid/free)
            $tenant->billing_status_expires_at = null;
            $tenant->save();
            
            // Log activity
            $this->activityRecorder->record(
                tenant: $tenant,
                eventType: EventType::PLAN_UPDATED,
                subject: $tenant,
                description: "Trial period expired - downgraded to free plan",
                metadata: [
                    'previous_plan' => $oldPlan,
                    'new_plan' => 'free',
                    'previous_billing_status' => $previousBillingStatus,
                    'expiration_date' => $expirationDate?->toIso8601String(),
                    'reason' => 'trial_expired',
                    'auto_downgraded' => true,
                    'processed_at' => now()->toIso8601String(),
                ]
            );
            
            // Send notification email to account owner
            $owner = $tenant->owner();
            if ($owner && $owner->email) {
                try {
                    Mail::to($owner->email)->send(new BillingTrialExpired($tenant, $owner, $expirationDate));
                } catch (\Exception $e) {
                    Log::error('Failed to send trial expiration email', [
                        'tenant_id' => $tenant->id,
                        'owner_email' => $owner->email,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
        
        return [
            'tenant_id' => $tenant->id,
            'tenant_name' => $tenant->name,
            'status' => 'expired',
            'action' => 'downgraded_to_free',
            'previous_plan' => $oldPlan,
            'new_plan' => 'free',
            'previous_billing_status' => 'trial',
            'expiration_date' => $expirationDate?->toIso8601String(),
            'dry_run' => $dryRun,
        ];
    }

    /**
     * Handle comped account expiration.
     * 
     * When comped account expires:
     * - Set plan to 'free'
     * - Clear billing_status (or keep as 'comped' if no expiration enforcement needed)
     * - Clear expiration date
     * - Log activity
     * 
     * TODO: Add grace period option
     * TODO: Add option to keep as comped indefinitely
     * TODO: Send notification before expiration
     * 
     * @param Tenant $tenant
     * @param bool $dryRun
     * @return array
     */
    protected function handleCompedExpiration(Tenant $tenant, bool $dryRun = false): array
    {
        $oldPlan = app(PlanService::class)->getCurrentPlan($tenant);
        $expirationDate = $tenant->billing_status_expires_at;
        $previousBillingStatus = $tenant->billing_status; // Store before clearing
        $previousEquivalentValue = $tenant->equivalent_plan_value; // Store before clearing
        
        if (!$dryRun) {
            // Set plan to free when comped expires
            // Clear comped status and set to free plan
            // TODO: Consider if we want to keep billing_status as 'comped' indefinitely
            // For now, we clear it after expiration and set plan to free
            $tenant->manual_plan_override = 'free';
            $tenant->billing_status = null; // Clear comped status after expiration
            $tenant->billing_status_expires_at = null;
            $tenant->equivalent_plan_value = null; // Clear equivalent value (no longer comped)
            $tenant->save();
            
            // Log activity
            $this->activityRecorder->record(
                tenant: $tenant,
                eventType: EventType::PLAN_UPDATED,
                subject: $tenant,
                description: "Comped account expired - downgraded to free plan",
                metadata: [
                    'previous_plan' => $oldPlan,
                    'new_plan' => 'free',
                    'previous_billing_status' => $previousBillingStatus,
                    'expiration_date' => $expirationDate?->toIso8601String(),
                    'previous_equivalent_plan_value' => $previousEquivalentValue,
                    'reason' => 'comped_expired',
                    'auto_downgraded' => true,
                    'processed_at' => now()->toIso8601String(),
                ]
            );
            
            // Send notification email to account owner
            $owner = $tenant->owner();
            if ($owner && $owner->email) {
                try {
                    Mail::to($owner->email)->send(new BillingCompedExpired($tenant, $owner, $expirationDate));
                } catch (\Exception $e) {
                    Log::error('Failed to send comped expiration email', [
                        'tenant_id' => $tenant->id,
                        'owner_email' => $owner->email,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
        
        return [
            'tenant_id' => $tenant->id,
            'tenant_name' => $tenant->name,
            'status' => 'expired',
            'action' => 'downgraded_to_free',
            'previous_plan' => $oldPlan,
            'new_plan' => 'free',
            'previous_billing_status' => 'comped',
            'expiration_date' => $expirationDate?->toIso8601String(),
            'dry_run' => $dryRun,
        ];
    }

    /**
     * Get accounts expiring soon (for warnings).
     * 
     * @param int $daysAhead Number of days to look ahead (default 7)
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAccountsExpiringSoon(int $daysAhead = 7): \Illuminate\Database\Eloquent\Collection
    {
        $now = now();
        $futureDate = $now->copy()->addDays($daysAhead);
        
        return Tenant::whereIn('billing_status', ['trial', 'comped'])
            ->whereNotNull('billing_status_expires_at')
            ->where('billing_status_expires_at', '>', $now)
            ->where('billing_status_expires_at', '<=', $futureDate)
            ->with('users') // For sending notifications
            ->get();
    }

    /**
     * Extend expiration date for an account.
     * 
     * @param Tenant $tenant
     * @param \Carbon\Carbon|string $newExpirationDate
     * @param string|null $reason Reason for extension (for audit log)
     * @return void
     */
    public function extendExpiration(Tenant $tenant, $newExpirationDate, ?string $reason = null): void
    {
        $oldExpiration = $tenant->billing_status_expires_at;
        
        if (is_string($newExpirationDate)) {
            $newExpirationDate = Carbon::parse($newExpirationDate);
        }
        
        $tenant->billing_status_expires_at = $newExpirationDate;
        $tenant->save();
        
        // Log activity
        $this->activityRecorder->record(
            tenant: $tenant,
            eventType: EventType::PLAN_UPDATED,
            subject: $tenant,
            description: "Billing status expiration extended",
            metadata: [
                'previous_expiration' => $oldExpiration?->toIso8601String(),
                'new_expiration' => $newExpirationDate->toIso8601String(),
                'billing_status' => $tenant->billing_status,
                'reason' => $reason ?? 'manual_extension',
                'extended_by_days' => $oldExpiration ? $oldExpiration->diffInDays($newExpirationDate) : null,
            ]
        );
    }

    /**
     * Set billing status with expiration for an account.
     * 
     * Used when manually assigning a plan with expiration.
     * 
     * @param Tenant $tenant
     * @param string $billingStatus 'trial' or 'comped'
     * @param string $planName Plan name to assign
     * @param int $months Number of months until expiration
     * @param float|null $equivalentPlanValue Equivalent plan value for comped accounts (sales insight only)
     * @param string|null $reason Reason for setting (for audit log)
     * @param User|null $adminUser Admin user who initiated the change (for email notifications and audit trail)
     * @return void
     */
    public function setBillingStatusWithExpiration(
        Tenant $tenant,
        string $billingStatus,
        string $planName,
        int $months,
        ?float $equivalentPlanValue = null,
        ?string $reason = null,
        ?User $adminUser = null
    ): void {
        // Validate billing_status
        if (!in_array($billingStatus, ['trial', 'comped'])) {
            throw new \InvalidArgumentException("Invalid billing_status. Must be 'trial' or 'comped'.");
        }
        
        // Validate plan
        if (!config("plans.{$planName}")) {
            throw new \InvalidArgumentException("Invalid plan name: {$planName}");
        }
        
        // Protection: Don't set expiration if account has active Stripe subscription
        $activeSubscription = $tenant->subscriptions()
            ->where('name', 'default')
            ->where('stripe_status', 'active')
            ->first();
        
        if ($activeSubscription) {
            throw new \RuntimeException(
                "Cannot set billing status with expiration for account with active Stripe subscription. " .
                "Account must be upgraded through Stripe billing portal."
            );
        }
        
        $oldPlan = app(PlanService::class)->getCurrentPlan($tenant);
        $oldBillingStatus = $tenant->billing_status;
        $oldExpiration = $tenant->billing_status_expires_at;
        
        // Calculate expiration date
        $expirationDate = now()->addMonths($months);
        
        // Set values
        $tenant->manual_plan_override = $planName;
        $tenant->billing_status = $billingStatus;
        $tenant->billing_status_expires_at = $expirationDate;
        
        // Set equivalent_plan_value for comped accounts (sales insight only)
        if ($billingStatus === 'comped' && $equivalentPlanValue !== null) {
            $tenant->equivalent_plan_value = $equivalentPlanValue;
        }
        
        $tenant->save();
        
        // Log activity with admin info
        $metadata = [
            'previous_plan' => $oldPlan,
            'new_plan' => $planName,
            'previous_billing_status' => $oldBillingStatus,
            'new_billing_status' => $billingStatus,
            'previous_expiration' => $oldExpiration?->toIso8601String(),
            'new_expiration' => $expirationDate->toIso8601String(),
            'months' => $months,
            'equivalent_plan_value' => $equivalentPlanValue,
            'reason' => $reason ?? 'manual_assignment',
            'description' => "Billing status set to {$billingStatus} with {$months}-month expiration",
        ];
        
        // Add admin info if provided
        if ($adminUser) {
            $metadata['admin_id'] = $adminUser->id;
            $metadata['admin_name'] = $adminUser->name ?? $adminUser->email;
            $metadata['admin_email'] = $adminUser->email;
        }
        
        ActivityRecorder::record(
            tenant: $tenant,
            eventType: EventType::PLAN_UPDATED,
            subject: $tenant,
            actor: $adminUser, // Pass admin as actor for proper audit trail
            brand: null,
            metadata: $metadata
        );
        
        // Send email notifications if admin user provided
        if ($adminUser) {
            $adminName = $adminUser->name ?? $adminUser->email ?? 'System Administrator';
            $owner = $tenant->owner();
            
            // Send to tenant owner
            if ($owner && $owner->email) {
                try {
                    Mail::to($owner->email)->send(new PlanChangedTenant(
                        $tenant,
                        $owner,
                        $oldPlan,
                        $planName,
                        $billingStatus,
                        $expirationDate,
                        $adminName
                    ));
                } catch (\Exception $e) {
                    Log::error('Failed to send plan change email to tenant', [
                        'tenant_id' => $tenant->id,
                        'owner_email' => $owner->email,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            
            // Send to admin (site owner) if different from initiating admin
            $siteOwner = User::find(1); // Site owner is user ID 1
            if ($siteOwner && $siteOwner->email && $siteOwner->id !== $adminUser->id) {
                try {
                    Mail::to($siteOwner->email)->send(new PlanChangedAdmin(
                        $tenant,
                        $oldPlan,
                        $planName,
                        $billingStatus,
                        $expirationDate,
                        $adminName
                    ));
                } catch (\Exception $e) {
                    Log::error('Failed to send plan change email to admin', [
                        'tenant_id' => $tenant->id,
                        'admin_email' => $siteOwner->email,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }
}
