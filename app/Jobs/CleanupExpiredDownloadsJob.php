<?php

namespace App\Jobs;

use App\Models\Download;
use App\Models\StorageBucket;
use Aws\S3\S3Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase D5 — Expiration Cleanup & Verification
 *
 * Two flows:
 * 1) D5: Expired (expires_at < now()) with artifact → delete ZIP, verify, set zip_deleted_at / cleanup_verified_at (or cleanup_failed_at). Record metrics. Do NOT force-delete.
 * 2) Hard-delete: hard_delete_at <= now() → delete ZIP, force-delete record (existing behavior).
 *
 * Safety: idempotent, does not throw on missing file, does not resurrect artifacts.
 * Logs: download.cleanup.started, .deleted, .verified, .missing_file, .failed, download.metrics.recorded.
 */
class CleanupExpiredDownloadsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [60, 300, 900];
    protected $batchSize = 50;

    private const ACTOR = 'system';

    public function handle(): void
    {
        $s3Client = app()->bound('download.cleanup.s3') ? app('download.cleanup.s3') : $this->createS3Client();
        $processedHardDelete = 0;
        $processedD5 = 0;
        $errorCount = 0;

        // D5: Expired with artifact (expires_at < now() AND zip_path AND !zip_deleted_at)
        Download::withTrashed()
            ->with(['tenant', 'assets'])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->whereNotNull('zip_path')
            ->whereNull('zip_deleted_at')
            ->chunk($this->batchSize, function ($downloads) use ($s3Client, &$processedD5, &$errorCount) {
                foreach ($downloads as $download) {
                    try {
                        $this->processExpiredDownload($download, $s3Client);
                        $processedD5++;
                    } catch (\Throwable $e) {
                        $errorCount++;
                        Log::error('download.cleanup.failed', [
                            'download_id' => $download->id,
                            'tenant_id' => $download->tenant_id,
                            'artifact_path' => $download->zip_path,
                            'bytes' => $download->zip_size_bytes,
                            'actor' => self::ACTOR,
                            'timestamp' => now()->toIso8601String(),
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        // Hard-delete: hard_delete_at <= now()
        Download::withTrashed()
            ->with('tenant')
            ->whereNotNull('hard_delete_at')
            ->where('hard_delete_at', '<=', now())
            ->chunk($this->batchSize, function ($downloads) use ($s3Client, &$processedHardDelete, &$errorCount) {
                foreach ($downloads as $download) {
                    if (! $download->shouldHardDelete()) {
                        continue;
                    }
                    try {
                        $zipPath = $download->zip_path;
                        if ($zipPath) {
                            $this->deleteZipFromStorage($download, $s3Client);
                        }
                        DB::transaction(fn () => $download->forceDelete());
                        $processedHardDelete++;
                    } catch (\Throwable $e) {
                        $errorCount++;
                        Log::error('download.cleanup.failed', [
                            'download_id' => $download->id,
                            'tenant_id' => $download->tenant_id,
                            'artifact_path' => $download->zip_path ?? null,
                            'bytes' => $download->zip_size_bytes,
                            'actor' => self::ACTOR,
                            'timestamp' => now()->toIso8601String(),
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        Log::info('[CleanupExpiredDownloadsJob] Job completed', [
            'processed_d5' => $processedD5,
            'processed_hard_delete' => $processedHardDelete,
            'error_count' => $errorCount,
        ]);
    }

    /**
     * D5: Process one expired download — delete artifact, verify, update timestamps, record metrics.
     */
    protected function processExpiredDownload(Download $download, S3Client $s3Client): void
    {
        $artifactPath = $download->zip_path;
        $bytes = $download->zip_size_bytes ?? 0;

        Log::info('download.cleanup.started', [
            'download_id' => $download->id,
            'tenant_id' => $download->tenant_id,
            'artifact_path' => $artifactPath,
            'bytes' => $bytes,
            'actor' => self::ACTOR,
            'timestamp' => now()->toIso8601String(),
        ]);

        $result = $this->deleteZipFromStorage($download, $s3Client);

        if ($result === 'missing') {
            Log::info('download.cleanup.missing_file', [
                'download_id' => $download->id,
                'tenant_id' => $download->tenant_id,
                'artifact_path' => $artifactPath,
                'bytes' => $bytes,
                'actor' => self::ACTOR,
                'timestamp' => now()->toIso8601String(),
            ]);
            $download->update([
                'zip_deleted_at' => now(),
                'cleanup_verified_at' => now(),
            ]);
            $download->refresh();
            $this->recordMetrics($download, $bytes);
            return;
        }

        if ($result === 'no_bucket' || $result === 'failure') {
            return;
        }

        // result === 'deleted' — verify file is gone
        $stillExists = $this->artifactExistsInStorage($download, $s3Client);
        if ($stillExists) {
            Log::warning('download.cleanup.failed', [
                'download_id' => $download->id,
                'tenant_id' => $download->tenant_id,
                'artifact_path' => $artifactPath,
                'bytes' => $bytes,
                'actor' => self::ACTOR,
                'timestamp' => now()->toIso8601String(),
                'message' => 'Verification failed: file still present after delete',
            ]);
            $download->update(['cleanup_failed_at' => now()]);
            return;
        }

        Log::info('download.cleanup.deleted', [
            'download_id' => $download->id,
            'tenant_id' => $download->tenant_id,
            'artifact_path' => $artifactPath,
            'bytes' => $bytes,
            'actor' => self::ACTOR,
            'timestamp' => now()->toIso8601String(),
        ]);
        Log::info('download.cleanup.verified', [
            'download_id' => $download->id,
            'tenant_id' => $download->tenant_id,
            'artifact_path' => $artifactPath,
            'bytes' => $bytes,
            'actor' => self::ACTOR,
            'timestamp' => now()->toIso8601String(),
        ]);

        $download->update([
            'zip_deleted_at' => now(),
            'cleanup_verified_at' => now(),
        ]);
        $download->refresh();
        $this->recordMetrics($download, $bytes);
    }

    /**
     * @return string 'deleted'|'missing'|'no_bucket'|'failure'
     */
    protected function deleteZipFromStorage(Download $download, S3Client $s3Client): string
    {
        $bucket = StorageBucket::where('tenant_id', $download->tenant_id)
            ->where('status', \App\Enums\StorageBucketStatus::ACTIVE)
            ->first();

        if (! $bucket) {
            Log::warning('download.cleanup.failed', [
                'download_id' => $download->id,
                'tenant_id' => $download->tenant_id,
                'artifact_path' => $download->zip_path,
                'bytes' => $download->zip_size_bytes,
                'actor' => self::ACTOR,
                'timestamp' => now()->toIso8601String(),
                'message' => 'No storage bucket for tenant',
            ]);
            return 'no_bucket';
        }

        if (! $s3Client->doesObjectExist($bucket->name, $download->zip_path)) {
            return 'missing';
        }

        try {
            $s3Client->deleteObject([
                'Bucket' => $bucket->name,
                'Key' => $download->zip_path,
            ]);
            return 'deleted';
        } catch (\Throwable $e) {
            Log::warning('download.cleanup.failed', [
                'download_id' => $download->id,
                'tenant_id' => $download->tenant_id,
                'artifact_path' => $download->zip_path,
                'bytes' => $download->zip_size_bytes,
                'actor' => self::ACTOR,
                'timestamp' => now()->toIso8601String(),
                'error' => $e->getMessage(),
            ]);
            return 'failure';
        }
    }

    protected function artifactExistsInStorage(Download $download, S3Client $s3Client): bool
    {
        $bucket = StorageBucket::where('tenant_id', $download->tenant_id)
            ->where('status', \App\Enums\StorageBucketStatus::ACTIVE)
            ->first();

        if (! $bucket || ! $download->zip_path) {
            return false;
        }

        try {
            return $s3Client->doesObjectExist($bucket->name, $download->zip_path);
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function recordMetrics(Download $download, ?int $bytes): void
    {
        $duration = $download->storageDurationSeconds();
        Log::info('download.metrics.recorded', [
            'download_id' => $download->id,
            'tenant_id' => $download->tenant_id,
            'artifact_path' => $download->zip_path,
            'bytes' => $bytes ?? $download->zip_size_bytes,
            'actor' => self::ACTOR,
            'timestamp' => now()->toIso8601String(),
            'total_bytes' => $bytes ?? $download->zip_size_bytes,
            'asset_count' => $download->assets->count(),
            'storage_duration_seconds' => $duration,
        ]);
    }

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

    public function failed(\Throwable $exception): void
    {
        Log::error('[CleanupExpiredDownloadsJob] Job failed permanently', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
