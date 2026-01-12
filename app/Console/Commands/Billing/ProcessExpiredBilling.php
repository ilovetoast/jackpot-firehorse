<?php

namespace App\Console\Commands\Billing;

use App\Services\BillingExpirationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Process Expired Billing Status Command
 * 
 * Checks and processes accounts with expired billing_status (trial/comped).
 * 
 * Scheduled: Run daily (recommended time: 2:00 AM)
 * 
 * Process:
 * 1. Find all accounts with billing_status_expires_at in the past
 * 2. Check if they have active Stripe subscription (protection)
 * 3. Handle expiration based on billing_status:
 *    - Trial: Downgrade to free plan
 *    - Comped: Downgrade to free plan
 * 4. Log all actions for audit trail
 * 
 * TODO: Add email notifications before expiration (separate command)
 * TODO: Add grace period logic
 * TODO: Add dry-run mode for testing
 */
class ProcessExpiredBilling extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billing:process-expired {--dry-run : Run without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process expired trial and comped accounts, downgrading to free plan';

    /**
     * Execute the console command.
     */
    public function handle(BillingExpirationService $service): int
    {
        $dryRun = $this->option('dry-run');
        
        if ($dryRun) {
            $this->info('Running in DRY-RUN mode - no changes will be made');
        }
        
        $this->info('Processing expired billing statuses...');
        
        try {
            $results = $service->processExpiredAccounts($dryRun);
            
            // Display results
            $this->info("Results:");
            $this->line("  - Expired Trials: {$results['expired_trials']}");
            $this->line("  - Expired Comped: {$results['expired_comped']}");
            $this->line("  - Skipped (upgraded): {$results['skipped']}");
            $this->line("  - Errors: {$results['errors']}");
            
            if (!empty($results['details'])) {
                $this->newLine();
                $this->info("Details:");
                foreach ($results['details'] as $detail) {
                    if (isset($detail['status'])) {
                        $status = $detail['status'] === 'expired' ? '<fg=green>expired</>' : 
                                 ($detail['status'] === 'skipped' ? '<fg=yellow>skipped</>' : 
                                 '<fg=red>error</>');
                        $this->line("  - {$detail['tenant_name']} ({$detail['tenant_id']}): {$status} - {$detail['message']}");
                    }
                }
            }
            
            // Log summary
            Log::info('Billing expiration processing completed', [
                'dry_run' => $dryRun,
                'results' => $results,
            ]);
            
            if ($results['errors'] > 0) {
                $this->warn('Some accounts had errors during processing. Check logs for details.');
                return Command::FAILURE;
            }
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
            Log::error('Failed to process expired billing statuses', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            $this->error('Failed to process expired billing statuses: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
