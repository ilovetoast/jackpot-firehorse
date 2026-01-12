<?php

namespace App\Console\Commands\Billing;

use App\Mail\BillingTrialExpiringWarning;
use App\Mail\BillingCompedExpiringWarning;
use App\Services\BillingExpirationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Check Expiring Billing Status Command
 * 
 * Checks for accounts expiring soon and sends warnings/notifications.
 * 
 * Scheduled: Run daily (recommended time: 9:00 AM)
 * 
 * Purpose:
 * - Find accounts expiring in next N days (default 7)
 * - Send notification emails to account owners
 * - Send alerts to admin team
 * - Log for audit trail
 * 
 * TODO: Implement email notification sending
 * TODO: Add admin dashboard alerts
 * TODO: Add configurable warning periods (7, 3, 1 days before)
 */
class CheckExpiringBilling extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billing:check-expiring {--days=7 : Number of days ahead to check}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check for accounts expiring soon and send warnings';

    /**
     * Execute the console command.
     */
    public function handle(BillingExpirationService $service): int
    {
        $daysAhead = (int) $this->option('days');
        
        $this->info("Checking for accounts expiring in the next {$daysAhead} days...");
        
        try {
            $expiringAccounts = $service->getAccountsExpiringSoon($daysAhead);
            
            $this->info("Found {$expiringAccounts->count()} account(s) expiring soon:");
            
            foreach ($expiringAccounts as $tenant) {
                $daysUntilExpiration = now()->diffInDays($tenant->billing_status_expires_at, false);
                $planName = app(\App\Services\PlanService::class)->getCurrentPlan($tenant);
                
                $this->line("  - {$tenant->name} ({$tenant->id})");
                $this->line("    Status: {$tenant->billing_status}");
                $this->line("    Plan: {$planName}");
                $this->line("    Expires: {$tenant->billing_status_expires_at->format('M d, Y')} ({$daysUntilExpiration} days)");
                
                // Send notification email to account owner
                $owner = $tenant->owner();
                if ($owner && $owner->email) {
                    try {
                        if ($tenant->billing_status === 'trial') {
                            Mail::to($owner->email)->send(new BillingTrialExpiringWarning($tenant, $owner, $tenant->billing_status_expires_at, $daysUntilExpiration));
                        } elseif ($tenant->billing_status === 'comped') {
                            Mail::to($owner->email)->send(new BillingCompedExpiringWarning($tenant, $owner, $tenant->billing_status_expires_at, $daysUntilExpiration));
                        }
                    } catch (\Exception $e) {
                        Log::error('Failed to send billing expiration warning email', [
                            'tenant_id' => $tenant->id,
                            'owner_email' => $owner->email,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
            
            if ($expiringAccounts->count() > 0) {
                Log::info('Found expiring billing statuses', [
                    'count' => $expiringAccounts->count(),
                    'days_ahead' => $daysAhead,
                    'accounts' => $expiringAccounts->map(fn ($t) => [
                        'id' => $t->id,
                        'name' => $t->name,
                        'billing_status' => $t->billing_status,
                        'expires_at' => $t->billing_status_expires_at?->toIso8601String(),
                    ])->toArray(),
                ]);
            }
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
            Log::error('Failed to check expiring billing statuses', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            $this->error('Failed to check expiring billing statuses: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
