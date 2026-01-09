<?php

namespace App\Console\Commands\Automation;

use App\Services\AIBudgetService;
use Illuminate\Console\Command;

/**
 * Reset Monthly Budgets Command
 *
 * Resets AI budget usage records for the new month.
 * Runs on the 1st of each month via scheduler.
 */
class ResetMonthlyBudgets extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ai:reset-monthly-budgets';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset monthly AI budget usage records for the new month';

    /**
     * Execute the console command.
     */
    public function handle(AIBudgetService $budgetService): int
    {
        $this->info('Resetting monthly AI budgets...');

        try {
            $budgetService->resetMonthlyBudgets();

            $this->info('Monthly AI budgets reset successfully.');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to reset monthly budgets: ' . $e->getMessage());

            return Command::FAILURE;
        }
    }
}
