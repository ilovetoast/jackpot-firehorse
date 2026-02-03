<?php

namespace App\Jobs;

use App\Enums\DownloadStatus;
use App\Enums\DownloadZipFailureReason;
use App\Enums\StorageBucketStatus;
use App\Enums\ZipStatus;
use App\Models\Download;
use App\Models\StorageBucket;
use App\Services\DownloadEventEmitter;
use App\Services\DownloadNotificationService;
use App\Services\DownloadZipFailureEscalationService;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use ZipArchive;

/**
 * Builds ZIP files for download groups.
 *
 * Chunked ZIP creation (resumable), failure classification, agent trigger, escalation.
 *
 * - Chunk size: 100 assets per batch
 * - Persists partial ZIP progress; resumes on retry
 * - Failure reason: timeout, disk_full, s3_read_error, permission_error, unknown
 * - Agent trigger: timeout OR failure_count >= 2
 * - Ticket escalation: failure_count >= 3 OR agent severity === "system"
 */
class BuildDownloadZipJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int Job timeout in seconds (15 min) — Phase D-3 */
    public int $timeout = 900;

    public int $tries = 3;

    /** @var array<int> Backoff delays (seconds) between retries — Phase D-3 */
    public array $backoff = [60, 300, 600];

    protected const CHUNK_SIZE = 100;

    public function __construct(
        public readonly string $downloadId
    ) {
        $this->onQueue(config('queue.downloads_queue', 'default'));
    }

    public function handle(): void
    {
        $download = Download::withTrashed()->findOrFail($this->downloadId);

        if (! $download->zip_build_started_at) {
            $download->forceFill(['zip_build_started_at' => now()])->saveQuietly();
        }

        Log::info('download.zip.build.started', [
            'download_id' => $download->id,
            'tenant_id' => $download->tenant_id,
            'asset_count' => $download->assets()->count(),
        ]);

        $startTime = microtime(true);

        if (! $this->canBuildZip($download)) {
            Log::warning('[BuildDownloadZipJob] Cannot build ZIP for download', [
                'download_id' => $download->id,
                'status' => $download->status->value,
                'zip_status' => $download->zip_status->value,
            ]);
            return;
        }

        if (! $download->zipNeedsRegeneration() && $download->hasZip()) {
            Log::info('[BuildDownloadZipJob] ZIP already exists and does not need regeneration', [
                'download_id' => $download->id,
                'zip_path' => $download->zip_path,
            ]);
            return;
        }

        $download->zip_status = ZipStatus::BUILDING;
        $download->save();

        try {
            $assets = $download->assets()->with('storageBucket')->orderBy('assets.id')->get();
            if ($assets->isEmpty()) {
                throw new \RuntimeException('Cannot build ZIP: download has no assets');
            }

            $bucket = $assets->first()->storageBucket ?? null;
            if (! $bucket) {
                throw new \RuntimeException('Cannot build ZIP: assets have no storage bucket');
            }

            // D-Progress: persist total chunks and heartbeat when starting (fresh) or resume
            $totalChunks = (int) ceil($assets->count() / self::CHUNK_SIZE);
            $chunkIndex = (int) ($download->zip_build_chunk_index ?? 0);
            if ($chunkIndex === 0) {
                $download->forceFill([
                    'zip_total_chunks' => $totalChunks,
                    'zip_build_chunk_index' => 0,
                    'zip_last_progress_at' => now(),
                ])->saveQuietly();
            } else {
                $download->forceFill(['zip_last_progress_at' => now()])->saveQuietly();
            }

            $s3Client = $this->createS3Client();

            $zipPath = $this->buildZipChunked($download, $assets, $bucket, $s3Client);

            $zipSizeBytes = $this->uploadZipToS3($download, $zipPath, $bucket, $s3Client);

            if ($download->zip_path && $download->zip_path !== $zipPath) {
                $this->deleteOldZip($download->zip_path, $bucket, $s3Client);
            }

            $durationSeconds = (int) round(microtime(true) - $startTime);

            $download->forceFill([
                'zip_build_completed_at' => now(),
                'zip_build_duration_seconds' => $durationSeconds,
                'failure_reason' => null,
                'failure_count' => 0,
                'last_failed_at' => null,
                'zip_build_chunk_index' => 0,
            ])->saveQuietly();

            $s3ZipKey = "downloads/{$download->id}/download.zip";
            $download->zip_status = ZipStatus::READY;
            $download->zip_path = $s3ZipKey;
            $download->zip_size_bytes = $zipSizeBytes;
            $options = $download->download_options ?? [];
            $options['generation_time_seconds'] = $durationSeconds;
            $options['asset_count'] = $assets->count();
            $download->download_options = $options;
            $download->save();

            DownloadEventEmitter::emitDownloadZipBuildSuccess($download, $zipSizeBytes);

            app(DownloadNotificationService::class)->notifyOnZipReady($download);

            Log::info('download.zip.build.completed', [
                'download_id' => $download->id,
                'tenant_id' => $download->tenant_id,
                'asset_count' => $download->assets()->count(),
                'zip_size_bytes' => $download->zip_size_bytes,
                'duration_ms' => $download->zipBuildDurationMs(),
                'attempt' => $this->attempts(),
            ]);

            if (file_exists($zipPath)) {
                @unlink($zipPath);
            }
        } catch (\Throwable $e) {
            $this->recordFailure($download, $e);
            DownloadEventEmitter::emitDownloadZipFailed($download, $e->getMessage());

            $shouldTriggerAgent = $download->failure_reason === DownloadZipFailureReason::TIMEOUT
                || $download->failure_count >= 2;
            if ($shouldTriggerAgent) {
                TriggerDownloadZipFailureAgentJob::dispatch($download->id);
            }

            if ($download->failure_count >= 3) {
                app(DownloadZipFailureEscalationService::class)->createTicketIfNeeded($download, null);
            }

            throw $e;
        }
    }

    protected function recordFailure(Download $download, \Throwable $e): void
    {
        $reason = $this->classifyFailure($e);

        $options = $download->download_options ?? [];
        if (! isset($options['estimated_bytes'])) {
            $options['estimated_bytes'] = $download->zip_size_bytes ?? 0;
        }
        $download->download_options = $options;
        $download->saveQuietly();

        $download->recordFailure($e, $reason);

        Log::error('download.zip.build.failed', [
            'download_id' => $download->id,
            'tenant_id' => $download->tenant_id,
            'failure_reason' => $reason->value,
            'failure_count' => $download->fresh()->failure_count,
            'attempt' => $this->attempts(),
            'exception' => $e->getMessage(),
        ]);
    }

    protected function classifyFailure(\Throwable $e): DownloadZipFailureReason
    {
        $msg = strtolower($e->getMessage());
        $class = $e::class;

        if ($class === \Illuminate\Queue\MaxAttemptsExceededException::class
            || $class === \Illuminate\Queue\TimeoutExceededException::class
            || str_contains($msg, 'timeout')
            || str_contains($msg, 'timed out')) {
            return DownloadZipFailureReason::TIMEOUT;
        }

        if (str_contains($msg, 'disk')
            || str_contains($msg, 'no space')
            || str_contains($msg, 'enospc')) {
            return DownloadZipFailureReason::DISK_FULL;
        }

        if ($e instanceof S3Exception
            || str_contains($msg, 's3')
            || str_contains($msg, 'aws')
            || str_contains($msg, 'getobject')) {
            return DownloadZipFailureReason::S3_READ_ERROR;
        }

        if (str_contains($msg, 'permission')
            || str_contains($msg, 'access denied')
            || str_contains($msg, 'forbidden')) {
            return DownloadZipFailureReason::PERMISSION_ERROR;
        }

        return DownloadZipFailureReason::UNKNOWN;
    }

    protected function buildZipChunked(
        Download $download,
        $assets,
        $bucket,
        S3Client $s3Client
    ): string {
        $tempZipPath = sys_get_temp_dir() . '/download_zip_' . $download->id . '.zip';
        $chunkIndex = (int) ($download->zip_build_chunk_index ?? 0);
        if (! file_exists($tempZipPath)) {
            $chunkIndex = 0;
            $download->forceFill(['zip_build_chunk_index' => 0])->saveQuietly();
        }
        $isResume = $chunkIndex > 0;

        $zip = new ZipArchive();
        $flags = $isResume ? ZipArchive::CREATE : ZipArchive::CREATE | ZipArchive::OVERWRITE;
        if ($zip->open($tempZipPath, $flags) !== true) {
            throw new \RuntimeException('Failed to create or open ZIP archive');
        }

        try {
            $skipCount = $chunkIndex * self::CHUNK_SIZE;
            $chunks = $assets->chunk(self::CHUNK_SIZE);
            $currentChunk = 0;

            foreach ($chunks as $chunkAssets) {
                if ($currentChunk < $chunkIndex) {
                    $currentChunk++;
                    continue;
                }

                foreach ($chunkAssets as $asset) {
                    try {
                        $assetPath = $asset->storage_root_path ?? $asset->path;
                        if (! $assetPath) {
                            Log::warning('[BuildDownloadZipJob] Asset missing storage path, skipping', [
                                'asset_id' => $asset->id,
                                'download_id' => $download->id,
                            ]);
                            continue;
                        }

                        $assetContent = $this->downloadAssetFromS3($bucket, $assetPath, $s3Client);
                        if ($assetContent === null) {
                            Log::warning('[BuildDownloadZipJob] Failed to download asset from S3, skipping', [
                                'asset_id' => $asset->id,
                                'download_id' => $download->id,
                            ]);
                            continue;
                        }

                        $zipFileName = $asset->original_filename ?? basename($assetPath);
                        $index = 0;
                        while ($zip->locateName($zipFileName) !== false) {
                            $index++;
                            $pathInfo = pathinfo($asset->original_filename ?? basename($assetPath));
                            $zipFileName = ($pathInfo['filename'] ?? 'file') . '_' . $index;
                            if (isset($pathInfo['extension'])) {
                                $zipFileName .= '.' . $pathInfo['extension'];
                            }
                        }

                        $zip->addFromString($zipFileName, $assetContent);
                    } catch (\Throwable $e) {
                        Log::warning('[BuildDownloadZipJob] Failed to add asset to ZIP, continuing', [
                            'asset_id' => $asset->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                $zip->close();
                $zip = new ZipArchive();
                $zip->open($tempZipPath, ZipArchive::CREATE);

                $currentChunk++;
                $download->forceFill([
                    'zip_build_chunk_index' => $currentChunk,
                    'zip_last_progress_at' => now(),
                ])->saveQuietly();
            }

            $zip->close();

            if (! file_exists($tempZipPath) || filesize($tempZipPath) === 0) {
                throw new \RuntimeException('ZIP file is empty or does not exist');
            }

            return $tempZipPath;
        } catch (\Throwable $e) {
            $zip->close();
            throw $e;
        }
    }

    protected function canBuildZip(Download $download): bool
    {
        if ($download->status === DownloadStatus::FAILED) {
            return false;
        }
        if ($download->zip_status === ZipStatus::BUILDING) {
            return true;
        }
        if ($download->zipNeedsRegeneration()) {
            return true;
        }
        if ($download->zip_status === ZipStatus::NONE && $download->status === DownloadStatus::READY) {
            return true;
        }
        if ($download->zip_status === ZipStatus::FAILED) {
            return true;
        }
        return false;
    }

    protected function downloadAssetFromS3($bucket, string $assetPath, S3Client $s3Client): ?string
    {
        try {
            if (! $s3Client->doesObjectExist($bucket->name, $assetPath)) {
                Log::warning('[BuildDownloadZipJob] Asset file does not exist in S3', [
                    'bucket' => $bucket->name,
                    'asset_path' => $assetPath,
                ]);
                return null;
            }

            $result = $s3Client->getObject([
                'Bucket' => $bucket->name,
                'Key' => $assetPath,
            ]);
            return (string) $result['Body'];
        } catch (S3Exception $e) {
            Log::error('[BuildDownloadZipJob] Failed to download asset from S3', [
                'bucket' => $bucket->name,
                'asset_path' => $assetPath,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    protected function uploadZipToS3(
        Download $download,
        string $zipPath,
        $bucket,
        S3Client $s3Client
    ): int {
        $zipSizeBytes = filesize($zipPath);
        $zipContent = file_get_contents($zipPath);
        $s3ZipPath = "downloads/{$download->id}/download.zip";

        try {
            $s3Client->putObject([
                'Bucket' => $bucket->name,
                'Key' => $s3ZipPath,
                'Body' => $zipContent,
                'ContentType' => 'application/zip',
                'ServerSideEncryption' => 'AES256',
            ]);

            Log::info('[BuildDownloadZipJob] ZIP uploaded to S3', [
                'download_id' => $download->id,
                's3_path' => $s3ZipPath,
                'size_bytes' => $zipSizeBytes,
                'bucket' => $bucket->name,
            ]);

            return $zipSizeBytes;
        } catch (S3Exception $e) {
            Log::error('[BuildDownloadZipJob] Failed to upload ZIP to S3', [
                'download_id' => $download->id,
                's3_path' => $s3ZipPath,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException("Failed to upload ZIP to S3: {$e->getMessage()}", 0, $e);
        }
    }

    protected function deleteOldZip(string $oldZipPath, $bucket, S3Client $s3Client): void
    {
        try {
            if ($s3Client->doesObjectExist($bucket->name, $oldZipPath)) {
                $s3Client->deleteObject([
                    'Bucket' => $bucket->name,
                    'Key' => $oldZipPath,
                ]);
                Log::info('[BuildDownloadZipJob] Old ZIP deleted from S3', [
                    'old_zip_path' => $oldZipPath,
                    'bucket' => $bucket->name,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('[BuildDownloadZipJob] Failed to delete old ZIP from S3 (non-fatal)', [
                'old_zip_path' => $oldZipPath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function createS3Client(): S3Client
    {
        if (app()->bound(S3Client::class)) {
            return app(S3Client::class);
        }

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
        $download = Download::find($this->downloadId);
        if (! $download) {
            Log::error('[BuildDownloadZipJob] Job failed, download not found', [
                'download_id' => $this->downloadId,
                'error' => $exception->getMessage(),
            ]);
            return;
        }

        $this->recordFailure($download, $exception);

        $shouldTriggerAgent = $download->failure_reason === DownloadZipFailureReason::TIMEOUT
            || $download->failure_count >= 2;
        if ($shouldTriggerAgent) {
            TriggerDownloadZipFailureAgentJob::dispatch($download->id);
        }

        if ($download->failure_count >= 3) {
            app(DownloadZipFailureEscalationService::class)->createTicketIfNeeded($download, null);
        }

        Log::error('[BuildDownloadZipJob] Job failed permanently', [
            'download_id' => $this->downloadId,
            'failure_count' => $download->failure_count,
            'failure_reason' => $download->failure_reason?->value,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
