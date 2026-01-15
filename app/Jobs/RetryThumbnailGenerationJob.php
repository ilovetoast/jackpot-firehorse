<?php

namespace App\Jobs;

use App\Jobs\GenerateThumbnailsJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Retry Thumbnail Generation Job
 *
 * Wrapper job that dispatches the existing GenerateThumbnailsJob for manual retry requests.
 * This job exists to provide a clear separation between retry requests and normal thumbnail generation,
 * while respecting the locked thumbnail pipeline by not modifying GenerateThumbnailsJob.
 *
 * IMPORTANT: This feature respects the locked thumbnail pipeline:
 * - Does not modify existing GenerateThumbnailsJob
 * - Does not mutate Asset.status (status represents visibility only)
 * - Simply dispatches the existing job with retry-specific logging
 */
class RetryThumbnailGenerationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @param string $assetId The asset ID to retry thumbnail generation for
     * @param int $userId The user ID who triggered the retry (for audit logging)
     * @param int $retryNumber The retry attempt number (1-based)
     */
    public function __construct(
        public readonly string $assetId,
        public readonly int $userId,
        public readonly int $retryNumber
    ) {}

    /**
     * Execute the job.
     *
     * Dispatches the existing GenerateThumbnailsJob without modification.
     * All thumbnail generation logic remains in the locked GenerateThumbnailsJob.
     */
    public function handle(): void
    {
        Log::info('[RetryThumbnailGenerationJob] Dispatching GenerateThumbnailsJob for retry', [
            'asset_id' => $this->assetId,
            'user_id' => $this->userId,
            'retry_number' => $this->retryNumber,
        ]);

        // Dispatch existing GenerateThumbnailsJob (unchanged, respects locked pipeline)
        // The existing job handles all thumbnail generation logic
        GenerateThumbnailsJob::dispatch($this->assetId);
    }
}
