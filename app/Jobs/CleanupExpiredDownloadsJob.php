<?php

namespace App\Jobs;

use App\Models\Download;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 🔒 Phase 3.1 — Downloader System (LOCKED)
 * 
 * Do not refactor or change behavior.
 * Future phases may consume outputs only.
 * 
 * Cleans up expired downloads by:
 * - Finding downloads where shouldHardDelete() === true
 * - Deleting ZIP files from S3
 * - Soft or hard deleting Download records as appropriate
 * 
 * Safety Rules:
 * - Never deletes asset files (only ZIP files)
 * - Never blocks user requests (runs in background)
 * - Job is idempotent
 * - Uses best-effort deletion patterns
 * - NO asset deletion here
 */
class CleanupExpiredDownloadsJob implements ShouldQueue
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
    public $backoff = [60, 300, 900]; // 1 minute, 5 minutes, 15 minutes

    /**
     * Number of downloads to process per batch.
     *
     * @var int
     */
    protected $batchSize = 50;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('[CleanupExpiredDownloadsJob] Job started');

        $s3Client = $this->createS3Client();
        $processedCount = 0;
        $deletedCount = 0;
        $errorCount = 0;

        // Process downloads in batches
        Download::withTrashed()
            ->with('tenant')
            ->where(function ($query) {
                // Find downloads ready for hard delete
                $query->whereNotNull('hard_delete_at')
                    ->where('hard_delete_at', '<=', now());
            })
            ->chunk($this->batchSize, function ($downloads) use ($s3Client, &$processedCount, &$deletedCount, &$errorCount) {
                foreach ($downloads as $download) {
                    $processedCount++;

                    try {
                        // Verify download should be hard deleted (double-check with model method)
                        if (!$download->shouldHardDelete()) {
                            Log::debug('[CleanupExpiredDownloadsJob] Download should not be hard deleted, skipping', [
                                'download_id' => $download->id,
                                'hard_delete_at' => $download->hard_delete_at?->toIso8601String(),
                            ]);
                            continue;
                        }

                        // Delete ZIP from S3 if it exists
                        if ($download->zip_path) {
                            $this->deleteZipFromS3($download, $s3Client);
                        }

                        // Permanently delete download record from database
                        DB::transaction(function () use ($download) {
                            // Force delete (bypass soft delete)
                            $download->forceDelete();
                        });

                        $deletedCount++;

                        Log::info('[CleanupExpiredDownloadsJob] Download cleanup completed', [
                            'download_id' => $download->id,
                            'had_zip' => !empty($download->zip_path),
                            'zip_path' => $download->zip_path,
                        ]);
                    } catch (\Throwable $e) {
                        $errorCount++;

                        Log::error('[CleanupExpiredDownloadsJob] Failed to cleanup download', [
                            'download_id' => $download->id,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);

                        // Continue with next download - best effort
                    }
                }
            });

        Log::info('[CleanupExpiredDownloadsJob] Job completed', [
            'processed_count' => $processedCount,
            'deleted_count' => $deletedCount,
            'error_count' => $errorCount,
        ]);
    }

    /**
     * Delete ZIP file from S3 (best-effort).
     * 
     * @param Download $download
     * @param S3Client $s3Client
     * @return void
     */
    protected function deleteZipFromS3(Download $download, S3Client $s3Client): void
    {
        try {
            // Get storage bucket from tenant
            // Note: We can't use asset->storageBucket here since we may not have assets loaded
            // Try to get bucket from tenant's first active bucket
            $bucket = \App\Models\StorageBucket::where('tenant_id', $download->tenant_id)
                ->where('status', \App\Enums\StorageBucketStatus::ACTIVE)
                ->first();

            if (!$bucket) {
                Log::warning('[CleanupExpiredDownloadsJob] Cannot find storage bucket for tenant, skipping ZIP deletion', [
                    'download_id' => $download->id,
                    'tenant_id' => $download->tenant_id,
                    'zip_path' => $download->zip_path,
                ]);
                return;
            }

            // Verify ZIP exists in S3
            if (!$s3Client->doesObjectExist($bucket->name, $download->zip_path)) {
                Log::debug('[CleanupExpiredDownloadsJob] ZIP does not exist in S3, skipping deletion', [
                    'download_id' => $download->id,
                    'zip_path' => $download->zip_path,
                    'bucket' => $bucket->name,
                ]);
                return;
            }

            // Delete ZIP from S3
            $s3Client->deleteObject([
                'Bucket' => $bucket->name,
                'Key' => $download->zip_path,
            ]);

            Log::info('[CleanupExpiredDownloadsJob] ZIP deleted from S3', [
                'download_id' => $download->id,
                'zip_path' => $download->zip_path,
                'bucket' => $bucket->name,
            ]);
        } catch (\Throwable $e) {
            // Best-effort deletion - log but don't fail
            Log::warning('[CleanupExpiredDownloadsJob] Failed to delete ZIP from S3 (non-fatal)', [
                'download_id' => $download->id,
                'zip_path' => $download->zip_path,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Create S3 client instance.
     * 
     * @return S3Client
     */
    protected function createS3Client(): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => config('filesystems.disks.s3.region'),
            'credentials' => [
                'key' => config('filesystems.disks.s3.key'),
                'secret' => config('filesystems.disks.s3.secret'),
            ],
            'endpoint' => config('filesystems.disks.s3.endpoint'),
            'use_path_style_endpoint' => config('filesystems.disks.s3.use_path_style_endpoint', false),
        ]);
    }

    /**
     * Handle job failure.
     * 
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('[CleanupExpiredDownloadsJob] Job failed permanently', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
