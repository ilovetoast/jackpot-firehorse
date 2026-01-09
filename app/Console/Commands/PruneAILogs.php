<?php

namespace App\Console\Commands;

use App\Models\AIAgentRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Prune AI Logs Command
 *
 * Deletes old AI agent run records based on retention policy.
 * This command is outlined but not fully implemented yet.
 *
 * Retention Policy:
 * - Retention period is configured in config/ai.php -> logging.retention_days
 * - Default: 30 days
 * - Agent runs older than retention period are deleted
 *
 * Usage:
 * - Run manually: php artisan ai:prune-logs
 * - Scheduled: Add to app scheduling (commented in bootstrap/app.php)
 *
 * TODO:
 * - Implement actual deletion logic
 * - Add dry-run mode for testing
 * - Add progress reporting for large deletions
 * - Add backup/export option before deletion
 * - Consider soft deletes instead of hard deletes
 * - Add metrics for deleted records
 */
class PruneAILogs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ai:prune-logs 
                            {--dry-run : Show what would be deleted without actually deleting}
                            {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete old AI agent run records based on retention policy';

    /**
     * Execute the console command.
     *
     * @return int Exit code (0 = success, 1 = error)
     */
    public function handle(): int
    {
        $retentionDays = config('ai.logging.retention_days', 30);
        $cutoffDate = now()->subDays($retentionDays);

        $this->info("Retention period: {$retentionDays} days");
        $this->info("Cutoff date: {$cutoffDate->toDateString()}");

        // Count records to be deleted
        $count = AIAgentRun::olderThan($retentionDays)->count();

        if ($count === 0) {
            $this->info('No records found to prune.');
            return Command::SUCCESS;
        }

        $this->warn("Found {$count} agent run(s) older than retention period.");

        if ($this->option('dry-run')) {
            $this->info('[DRY RUN] Would delete the following records:');
            $this->displaySampleRecords($retentionDays);
            return Command::SUCCESS;
        }

        if (!$this->option('force')) {
            if (!$this->confirm('Are you sure you want to delete these records?', false)) {
                $this->info('Deletion cancelled.');
                return Command::SUCCESS;
            }
        }

        // TODO: Implement actual deletion logic
        // For now, just show what would be deleted
        $this->warn('⚠️  Deletion logic not yet implemented.');
        $this->info('This command is currently a stub. Deletion logic needs to be implemented.');
        
        // Placeholder for actual deletion:
        // try {
        //     $deleted = AIAgentRun::olderThan($retentionDays)->delete();
        //     $this->info("Successfully deleted {$deleted} agent run(s).");
        //     return Command::SUCCESS;
        // } catch (\Exception $e) {
        //     $this->error("Failed to delete records: {$e->getMessage()}");
        //     return Command::FAILURE;
        // }

        return Command::SUCCESS;
    }

    /**
     * Display sample records that would be deleted.
     *
     * @param int $retentionDays Retention period in days
     * @return void
     */
    protected function displaySampleRecords(int $retentionDays): void
    {
        $records = AIAgentRun::olderThan($retentionDays)
            ->orderBy('started_at', 'asc')
            ->limit(10)
            ->get(['id', 'agent_id', 'task_type', 'status', 'started_at', 'estimated_cost']);

        if ($records->isEmpty()) {
            return;
        }

        $this->table(
            ['ID', 'Agent', 'Task', 'Status', 'Started', 'Cost'],
            $records->map(function ($record) {
                return [
                    $record->id,
                    $record->agent_id,
                    $record->task_type,
                    $record->status,
                    $record->started_at->toDateString(),
                    '$' . number_format($record->estimated_cost, 6),
                ];
            })->toArray()
        );

        $totalCount = AIAgentRun::olderThan($retentionDays)->count();
        if ($totalCount > 10) {
            $this->info("... and " . ($totalCount - 10) . " more record(s).");
        }
    }
}
