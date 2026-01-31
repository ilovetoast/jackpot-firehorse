<?php

namespace App\Jobs;

use App\Enums\DownloadStatus;
use App\Enums\StorageBucketStatus;
use App\Enums\ZipStatus;
use App\Models\Download;
use App\Models\StorageBucket;
use App\Services\DownloadEventEmitter;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * 🔒 Phase 3.1 — Downloader System (LOCKED)
 * 
 * Do not refactor or change behavior.
 * Future phases may consume outputs only.
 * 
 * Builds ZIP files for download groups by:
 * - Streaming asset files from S3
 * - Creating ZIP archive
 * - Uploading ZIP directly to S3
 * - Updating download model with ZIP metadata
 * 
 * Safety Rules:
 * - Never deletes asset files
 * - Never blocks user requests (runs in background)
 * - Job is idempotent
 * - Uses best-effort deletion patterns
 */
class BuildDownloadZipJob implements ShouldQueue
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
     * Create a new job instance.
     */
    public function __construct(
        public readonly string $downloadId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $startTime = microtime(true);
        Log::info('[BuildDownloadZipJob] Job started', [
            'download_id' => $this->downloadId,
        ]);

        // Find download (including soft-deleted, as cleanup may still need ZIP)
        $download = Download::withTrashed()->findOrFail($this->downloadId);

        // Verify download exists and status allows ZIP build
        if (!$this->canBuildZip($download)) {
            Log::warning('[BuildDownloadZipJob] Cannot build ZIP for download', [
                'download_id' => $download->id,
                'status' => $download->status->value,
                'zip_status' => $download->zip_status->value,
            ]);
            return;
        }

        // Verify ZIP needs regeneration
        if (!$download->zipNeedsRegeneration() && $download->hasZip()) {
            Log::info('[BuildDownloadZipJob] ZIP already exists and does not need regeneration', [
                'download_id' => $download->id,
                'zip_path' => $download->zip_path,
            ]);
            return;
        }

        // Update status to BUILDING (idempotent - may already be BUILDING)
        $download->zip_status = ZipStatus::BUILDING;
        $download->save();

        try {
            // Load assets for this download (with storage bucket relationship)
            $assets = $download->assets()->with('storageBucket')->get();
            
            if ($assets->isEmpty()) {
                throw new \RuntimeException('Cannot build ZIP: download has no assets');
            }

            // Get storage bucket from first asset (all assets should be in same bucket for a tenant)
            $bucket = $assets->first()->storageBucket ?? null;
            if (!$bucket) {
                throw new \RuntimeException('Cannot build ZIP: assets have no storage bucket');
            }

            // Create S3 client
            $s3Client = $this->createS3Client();

            // Build ZIP file
            $zipPath = $this->buildZip($download, $assets, $bucket, $s3Client);

            // Upload ZIP to S3
            $zipSizeBytes = $this->uploadZipToS3($download, $zipPath, $bucket, $s3Client);

            // Delete old ZIP from S3 if it exists and is different
            if ($download->zip_path && $download->zip_path !== $zipPath) {
                $this->deleteOldZip($download->zip_path, $bucket, $s3Client);
            }

            // Update download model (store S3 key, not local path — Phase D1 fix)
            $s3ZipKey = "downloads/{$download->id}/download.zip";
            $download->zip_status = ZipStatus::READY;
            $download->zip_path = $s3ZipKey;
            $download->zip_size_bytes = $zipSizeBytes;
            $options = $download->download_options ?? [];
            $options['generation_time_seconds'] = round(microtime(true) - $startTime, 2);
            $options['asset_count'] = $assets->count();
            $download->download_options = $options;
            $download->save();

            // Phase 3.1 Step 5: Emit ZIP build success event
            DownloadEventEmitter::emitDownloadZipBuildSuccess($download, $zipSizeBytes);

            Log::info('[BuildDownloadZipJob] ZIP build completed successfully', [
                'download_id' => $download->id,
                'zip_path' => $zipPath,
                'zip_size_bytes' => $zipSizeBytes,
                'asset_count' => $assets->count(),
            ]);

            // Clean up temporary ZIP file
            if (file_exists($zipPath)) {
                @unlink($zipPath);
            }
        } catch (\Throwable $e) {
            Log::error('[BuildDownloadZipJob] ZIP build failed', [
                'download_id' => $download->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Update download status to FAILED
            $download->zip_status = ZipStatus::FAILED;
            $download->save();

            // Phase 3.1 Step 5: Emit ZIP build failure event
            DownloadEventEmitter::emitDownloadZipFailed($download, $e->getMessage());

            throw $e;
        }
    }

    /**
     * Check if ZIP can be built for this download.
     * 
     * @param Download $download
     * @return bool
     */
    protected function canBuildZip(Download $download): bool
    {
        // Download status must allow ZIP build
        if ($download->status === DownloadStatus::FAILED) {
            return false;
        }

        // If ZIP is already BUILDING, allow retry (idempotent)
        if ($download->zip_status === ZipStatus::BUILDING) {
            return true;
        }

        // ZIP needs regeneration check
        if ($download->zipNeedsRegeneration()) {
            return true;
        }

        // If no ZIP exists and status is ready, can build
        if ($download->zip_status === ZipStatus::NONE && $download->status === DownloadStatus::READY) {
            return true;
        }

        return false;
    }

    /**
     * Build ZIP file from assets.
     * 
     * @param Download $download
     * @param \Illuminate\Database\Eloquent\Collection $assets
     * @param \App\Models\StorageBucket $bucket
     * @param S3Client $s3Client
     * @return string Path to temporary ZIP file
     */
    protected function buildZip(
        Download $download,
        $assets,
        $bucket,
        S3Client $s3Client
    ): string {
        $tempZipPath = tempnam(sys_get_temp_dir(), 'download_zip_') . '.zip';
        
        $zip = new ZipArchive();
        if ($zip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Failed to create ZIP archive');
        }

        try {
            foreach ($assets as $asset) {
                try {
                    // Get asset file path from S3
                    $assetPath = $asset->storage_root_path ?? $asset->path;
                    if (!$assetPath) {
                        Log::warning('[BuildDownloadZipJob] Asset missing storage path, skipping', [
                            'asset_id' => $asset->id,
                            'download_id' => $download->id,
                        ]);
                        continue;
                    }

                    // Download asset from S3 to memory
                    $assetContent = $this->downloadAssetFromS3($bucket, $assetPath, $s3Client);
                    
                    if ($assetContent === null) {
                        Log::warning('[BuildDownloadZipJob] Failed to download asset from S3, skipping', [
                            'asset_id' => $asset->id,
                            'asset_path' => $assetPath,
                            'download_id' => $download->id,
                        ]);
                        continue;
                    }

                    // Add to ZIP with asset filename
                    $zipFileName = $asset->original_filename ?? basename($assetPath);
                    
                    // Handle duplicate filenames by prefixing with index
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

                    Log::debug('[BuildDownloadZipJob] Added asset to ZIP', [
                        'asset_id' => $asset->id,
                        'zip_file_name' => $zipFileName,
                        'asset_size' => strlen($assetContent),
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('[BuildDownloadZipJob] Failed to add asset to ZIP, continuing', [
                        'asset_id' => $asset->id,
                        'error' => $e->getMessage(),
                    ]);
                    // Continue with other assets - best effort
                }
            }

            $zip->close();

            if (!file_exists($tempZipPath) || filesize($tempZipPath) === 0) {
                throw new \RuntimeException('ZIP file is empty or does not exist');
            }

            return $tempZipPath;
        } catch (\Throwable $e) {
            // Clean up ZIP file on error
            $zip->close();
            if (file_exists($tempZipPath)) {
                @unlink($tempZipPath);
            }
            throw $e;
        }
    }

    /**
     * Download asset file from S3 to memory.
     * 
     * @param \App\Models\StorageBucket $bucket
     * @param string $assetPath S3 key
     * @param S3Client $s3Client
     * @return string|null Asset content or null if download failed
     */
    protected function downloadAssetFromS3($bucket, string $assetPath, S3Client $s3Client): ?string
    {
        try {
            if (!$s3Client->doesObjectExist($bucket->name, $assetPath)) {
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

            $body = $result['Body'];
            return (string) $body;
        } catch (S3Exception $e) {
            Log::error('[BuildDownloadZipJob] Failed to download asset from S3', [
                'bucket' => $bucket->name,
                'asset_path' => $assetPath,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Upload ZIP file to S3.
     * 
     * @param Download $download
     * @param string $zipPath Local path to ZIP file
     * @param \App\Models\StorageBucket $bucket
     * @param S3Client $s3Client
     * @return int ZIP file size in bytes
     */
    protected function uploadZipToS3(
        Download $download,
        string $zipPath,
        $bucket,
        S3Client $s3Client
    ): int {
        $zipSizeBytes = filesize($zipPath);
        $zipContent = file_get_contents($zipPath);

        // Generate S3 path for ZIP: downloads/{download_id}/download.zip
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

    /**
     * Delete old ZIP file from S3 (best-effort).
     * 
     * @param string $oldZipPath S3 key
     * @param \App\Models\StorageBucket $bucket
     * @param S3Client $s3Client
     * @return void
     */
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
            // Best-effort deletion - log but don't fail
            Log::warning('[BuildDownloadZipJob] Failed to delete old ZIP from S3 (non-fatal)', [
                'old_zip_path' => $oldZipPath,
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
        Log::error('[BuildDownloadZipJob] Job failed permanently', [
            'download_id' => $this->downloadId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Mark download ZIP status as FAILED
        $download = Download::find($this->downloadId);
        if ($download) {
            $download->zip_status = ZipStatus::FAILED;
            $download->save();
        }
    }
}
