<?php

namespace App\Jobs;

use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Services\AssetProcessingFailureService;
use App\Services\MetadataResolutionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Resolve Metadata Candidates Job
 *
 * Phase B8: Resolves metadata candidates to active values in asset_metadata.
 *
 * Runs AFTER:
 * - PopulateAutomaticMetadataJob (candidates created)
 *
 * Rules:
 * - Resolves highest confidence candidates
 * - Never overwrites manual overrides
 * - Idempotent (safe to re-run)
 */
class ResolveMetadataCandidatesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public $backoff = [60, 300, 900];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly string $assetId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(MetadataResolutionService $resolver): void
    {
        $asset = Asset::findOrFail($this->assetId);
        \App\Services\UploadDiagnosticLogger::jobStart('ResolveMetadataCandidatesJob', $asset->id);

        // Skip if asset is not visible
        if ($asset->status !== AssetStatus::VISIBLE) {
            Log::info('[ResolveMetadataCandidatesJob] Skipping - asset not visible', [
                'asset_id' => $asset->id,
                'status' => $asset->status->value,
            ]);
            \App\Services\UploadDiagnosticLogger::jobSkip('ResolveMetadataCandidatesJob', $asset->id, 'asset_not_visible');
            return;
        }

        // Resolve candidates
        $results = $resolver->resolveCandidates($asset);

        Log::info('[ResolveMetadataCandidatesJob] Completed', [
            'asset_id' => $asset->id,
            'resolved' => count($results['resolved']),
            'skipped' => count($results['skipped']),
            'skipped_reasons' => array_column($results['skipped'], 'reason'),
        ]);
        \App\Services\UploadDiagnosticLogger::jobComplete('ResolveMetadataCandidatesJob', $asset->id, [
            'resolved' => count($results['resolved']),
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $asset = Asset::find($this->assetId);

        if ($asset) {
            app(AssetProcessingFailureService::class)->recordFailure(
                $asset,
                self::class,
                $exception,
                $this->attempts(),
                true // preserveVisibility: uploaded assets must never disappear from grid
            );
        }
    }
}
