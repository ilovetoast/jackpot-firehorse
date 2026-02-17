<?php

namespace App\Console\Commands;

use App\Jobs\GenerateAssetEmbeddingJob;
use App\Jobs\ProcessAssetJob;
use App\Jobs\ScoreAssetComplianceJob;
use App\Models\Asset;
use App\Services\AnalysisStatusLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Recover Stuck Compliance Pipeline Command
 *
 * Finds assets stuck in transient analysis_status (generating_thumbnails,
 * extracting_metadata, generating_embedding, scoring) for longer than the
 * configured timeout. Resets to a safe state and requeues the appropriate job.
 *
 * Prevents production deadlocks when queue workers die mid-job.
 *
 * Usage:
 *   php artisan compliance:recover-stuck
 */
class RecoverStuckComplianceCommand extends Command
{
    protected $signature = 'compliance:recover-stuck
                            {--dry-run : Show what would be recovered without making changes}
                            {--minutes= : Override timeout (default from config)}';

    protected $description = 'Recover assets stuck in analysis pipeline (reset and requeue)';

    protected array $stuckStatuses = [
        'generating_thumbnails',
        'extracting_metadata',
        'generating_embedding',
        'scoring',
    ];

    public function handle(): int
    {
        $minutes = (int) ($this->option('minutes') ?? config('compliance.stuck_timeout_minutes', 30));
        $cutoff = now()->subMinutes($minutes);

        $this->info("Checking for assets stuck > {$minutes} minutes...");

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        $stuck = Asset::whereIn('analysis_status', $this->stuckStatuses)
            ->where('updated_at', '<', $cutoff)
            ->get();

        if ($stuck->isEmpty()) {
            $this->info('No stuck assets found.');

            return Command::SUCCESS;
        }

        $this->warn("Found {$stuck->count()} stuck asset(s):");

        foreach ($stuck as $asset) {
            $status = $asset->analysis_status ?? 'unknown';
            $minutesStuck = $asset->updated_at ? now()->diffInMinutes($asset->updated_at) : '?';

            $this->line("  - Asset {$asset->id} ({$asset->title}): status={$status}, stuck ~{$minutesStuck} min");

            if ($this->option('dry-run')) {
                $this->comment("    [DRY RUN] Would reset and requeue");
                continue;
            }

            $this->recoverAsset($asset);
        }

        if (! $this->option('dry-run')) {
            $this->info("\n✓ Recovered {$stuck->count()} stuck asset(s).");
            Log::info('[RecoverStuckComplianceCommand] Completed', [
                'recovered_count' => $stuck->count(),
                'timeout_minutes' => $minutes,
            ]);
        }

        return Command::SUCCESS;
    }

    protected function recoverAsset(Asset $asset): void
    {
        $status = $asset->analysis_status ?? '';

        switch ($status) {
            case 'generating_thumbnails':
            case 'extracting_metadata':
                $this->resetToUploadingAndRequeue($asset);
                break;
            case 'generating_embedding':
                GenerateAssetEmbeddingJob::dispatch($asset->id);
                $this->line("    ✓ Requeued GenerateAssetEmbeddingJob");
                break;
            case 'scoring':
                ScoreAssetComplianceJob::dispatch($asset->id);
                $this->line("    ✓ Requeued ScoreAssetComplianceJob");
                break;
            default:
                $this->warn("    Unknown status: {$status}");
        }
    }

    protected function resetToUploadingAndRequeue(Asset $asset): void
    {
        $metadata = $asset->metadata ?? [];
        unset($metadata['processing_started'], $metadata['processing_started_at']);
        $previousStatus = $asset->analysis_status ?? 'unknown';

        $asset->update([
            'analysis_status' => 'uploading',
            'metadata' => $metadata,
        ]);
        AnalysisStatusLogger::log($asset, $previousStatus, 'uploading', 'RecoverStuckComplianceCommand');

        ProcessAssetJob::dispatch($asset->id);
        $this->line("    ✓ Reset to uploading, requeued ProcessAssetJob");
    }
}
