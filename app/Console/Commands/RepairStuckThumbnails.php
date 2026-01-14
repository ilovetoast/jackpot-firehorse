<?php

namespace App\Console\Commands;

use App\Services\ThumbnailTimeoutGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Repair Stuck Thumbnails Command
 * 
 * Finds and repairs assets that are stuck in thumbnail_status = processing
 * for longer than the timeout threshold (5 minutes).
 * 
 * This command is for safety and debugging - it ensures no assets remain
 * in processing state indefinitely.
 * 
 * Usage:
 *   php artisan thumbnails:repair-stuck
 */
class RepairStuckThumbnails extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'thumbnails:repair-stuck 
                            {--dry-run : Show what would be repaired without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Find and repair assets stuck in thumbnail processing state';

    /**
     * Execute the console command.
     */
    public function handle(ThumbnailTimeoutGuard $timeoutGuard): int
    {
        $this->info('Checking for stuck thumbnail processing jobs...');
        
        if ($this->option('dry-run')) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }
        
        // Find stuck assets
        $stuckAssets = \App\Models\Asset::where('thumbnail_status', \App\Enums\ThumbnailStatus::PROCESSING)
            ->where(function ($query) {
                // Assets with thumbnail_started_at older than 5 minutes
                $query->whereNotNull('thumbnail_started_at')
                    ->where('thumbnail_started_at', '<', now()->subMinutes(5))
                    // OR assets without thumbnail_started_at but created more than 5 minutes ago
                    // (fallback for assets that started processing before thumbnail_started_at was added)
                    ->orWhere(function ($q) {
                        $q->whereNull('thumbnail_started_at')
                            ->where('created_at', '<', now()->subMinutes(5));
                    });
            })
            ->get();
        
        if ($stuckAssets->isEmpty()) {
            $this->info('No stuck assets found.');
            return Command::SUCCESS;
        }
        
        $this->warn("Found {$stuckAssets->count()} stuck asset(s):");
        
        foreach ($stuckAssets as $asset) {
            $startTime = $asset->thumbnail_started_at ?? $asset->created_at;
            $minutesStuck = $startTime ? now()->diffInMinutes($startTime) : 'unknown';
            
            $this->line("  - Asset ID: {$asset->id}");
            $this->line("    Title: {$asset->title}");
            $this->line("    Started: {$startTime}");
            $this->line("    Stuck for: {$minutesStuck} minutes");
            
            if (!$this->option('dry-run')) {
                // Actually repair the asset
                $timeoutGuard->checkAndRepair($asset);
                $this->info("    ✓ Marked as FAILED");
            } else {
                $this->comment("    [DRY RUN] Would mark as FAILED");
            }
        }
        
        if (!$this->option('dry-run')) {
            $this->info("\n✓ Repaired {$stuckAssets->count()} stuck asset(s).");
            Log::info('[RepairStuckThumbnails] Command completed', [
                'repaired_count' => $stuckAssets->count(),
            ]);
        } else {
            $this->info("\n[DRY RUN] Would repair {$stuckAssets->count()} stuck asset(s).");
        }
        
        return Command::SUCCESS;
    }
}
