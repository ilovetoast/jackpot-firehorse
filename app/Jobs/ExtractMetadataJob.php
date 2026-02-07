<?php

namespace App\Jobs;

use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\AssetEvent;
use App\Services\AssetProcessingFailureService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExtractMetadataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     * Never retry forever - enforce maximum attempts.
     *
     * @var int
     */
    public $tries = 3; // Maximum retry attempts (enforced by AssetProcessingFailureService)

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
    public function handle(): void
    {
        Log::info('[ExtractMetadataJob] Job started', [
            'asset_id' => $this->assetId,
        ]);
        
        $asset = Asset::findOrFail($this->assetId);

        // Idempotency: Check if metadata already extracted
        $existingMetadata = $asset->metadata ?? [];
        if (isset($existingMetadata['metadata_extracted']) && $existingMetadata['metadata_extracted'] === true) {
            Log::info('[ExtractMetadataJob] Metadata extraction skipped - already extracted', [
                'asset_id' => $asset->id,
            ]);
            // Job chaining is handled by Bus::chain() in ProcessAssetJob
            // Chain will continue to next job automatically
            return;
        }

        // Ensure asset is VISIBLE (not hidden or failed)
        // Asset.status represents VISIBILITY, not processing progress
        // Processing jobs must NOT mutate Asset.status (assets must remain visible in grid)
        if ($asset->status !== AssetStatus::VISIBLE) {
            Log::warning('Metadata extraction skipped - asset is not visible', [
                'asset_id' => $asset->id,
                'status' => $asset->status->value,
            ]);
            return;
        }

        // Extract metadata (stub implementation)
        $metadata = $this->extractMetadata($asset);

        // Update asset metadata
        $currentMetadata = $asset->metadata ?? [];
        $currentMetadata['metadata_extracted'] = true;
        $currentMetadata['extracted_at'] = now()->toIso8601String();
        $currentMetadata['metadata'] = $metadata;

        $asset->update([
            'metadata' => $currentMetadata,
        ]);

        // Emit metadata extracted event
        AssetEvent::create([
            'tenant_id' => $asset->tenant_id,
            'brand_id' => $asset->brand_id,
            'asset_id' => $asset->id,
            'user_id' => null,
            'event_type' => 'asset.metadata.extracted',
            'metadata' => [
                'job' => 'ExtractMetadataJob',
                'metadata_keys' => array_keys($metadata),
            ],
            'created_at' => now(),
        ]);

        Log::info('Metadata extracted', [
            'asset_id' => $asset->id,
            'metadata_keys' => array_keys($metadata),
        ]);

        // Job chaining is handled by Bus::chain() in ProcessAssetJob
        // No need to dispatch next job here
    }

    /**
     * Extract metadata from asset.
     *
     * @param Asset $asset
     * @return array
     */
    protected function extractMetadata(Asset $asset): array
    {
        $fileTypeService = app(\App\Services\FileTypeService::class);
        $fileType = $fileTypeService->detectFileTypeFromAsset($asset);

        // Basic metadata for all file types
        $metadata = [
            'original_filename' => $asset->original_filename,
            'size_bytes' => $asset->size_bytes,
            'mime_type' => $asset->mime_type,
            'extracted_by' => 'extract_metadata_job',
        ];

        // Video-specific metadata extraction
        if ($fileType === 'video') {
            $videoMetadata = $this->extractVideoMetadata($asset);
            $metadata = array_merge($metadata, $videoMetadata);
        }

        return $metadata;
    }

    /**
     * Extract video metadata (duration, width, height).
     *
     * @param Asset $asset
     * @return array
     */
    protected function extractVideoMetadata(Asset $asset): array
    {
        if (!$asset->storage_root_path || !$asset->storageBucket) {
            Log::warning('[ExtractMetadataJob] Cannot extract video metadata - missing storage path or bucket', [
                'asset_id' => $asset->id,
            ]);
            return [];
        }

        $bucket = $asset->storageBucket;
        $sourceS3Path = $asset->storage_root_path;

        Log::info('[ExtractMetadataJob] Extracting video metadata', [
            'asset_id' => $asset->id,
            'source_s3_path' => $sourceS3Path,
        ]);

        try {
            // Download video to temporary location
            $tempPath = $this->downloadFromS3($bucket, $sourceS3Path);

            if (!file_exists($tempPath) || filesize($tempPath) === 0) {
                throw new \RuntimeException('Downloaded source video file is invalid or empty');
            }

            try {
                // Get video information using FFprobe
                $ffmpegPath = $this->findFFmpegPath();
                if (!$ffmpegPath) {
                    Log::warning('[ExtractMetadataJob] FFmpeg not found - skipping video metadata extraction', [
                        'asset_id' => $asset->id,
                    ]);
                    return [];
                }

                $videoInfo = $this->getVideoInfo($tempPath, $ffmpegPath);
                $duration = (int) round($videoInfo['duration'] ?? 0);
                $width = (int) ($videoInfo['width'] ?? 0);
                $height = (int) ($videoInfo['height'] ?? 0);

                // Update asset with video metadata
                $asset->update([
                    'video_duration' => $duration > 0 ? $duration : null,
                    'video_width' => $width > 0 ? $width : null,
                    'video_height' => $height > 0 ? $height : null,
                ]);

                Log::info('[ExtractMetadataJob] Video metadata extracted', [
                    'asset_id' => $asset->id,
                    'duration' => $duration,
                    'width' => $width,
                    'height' => $height,
                ]);

                return [
                    'video_duration' => $duration,
                    'video_width' => $width,
                    'video_height' => $height,
                ];
            } finally {
                // Clean up temporary file
                if (file_exists($tempPath)) {
                    @unlink($tempPath);
                }
            }
        } catch (\Exception $e) {
            Log::error('[ExtractMetadataJob] Failed to extract video metadata', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
            // Don't throw - metadata extraction failure should not block processing
            return [];
        }
    }

    /**
     * Download file from S3 to temporary location.
     *
     * @param \App\Models\StorageBucket $bucket
     * @param string $s3Key
     * @return string Path to temporary file
     * @throws \RuntimeException If download fails
     */
    protected function downloadFromS3($bucket, string $s3Key): string
    {
        if (!class_exists(\Aws\S3\S3Client::class)) {
            throw new \RuntimeException('AWS SDK not installed. Install aws/aws-sdk-php.');
        }

        $config = [
            'version' => 'latest',
            'region' => config('storage.default_region', config('filesystems.disks.s3.region', 'us-east-1')),
        ];
        if (config('filesystems.disks.s3.endpoint')) {
            $config['endpoint'] = config('filesystems.disks.s3.endpoint');
            $config['use_path_style_endpoint'] = config('filesystems.disks.s3.use_path_style_endpoint', false);
        }
        $s3Client = new \Aws\S3\S3Client($config);

        try {
            $result = $s3Client->getObject([
                'Bucket' => $bucket->name,
                'Key' => $s3Key,
            ]);

            $body = $result['Body'];
            $bodyContents = (string) $body;
            $contentLength = strlen($bodyContents);

            if ($contentLength === 0) {
                throw new \RuntimeException("Downloaded file from S3 is empty (size: 0 bytes)");
            }

            $tempPath = tempnam(sys_get_temp_dir(), 'video_metadata_');
            file_put_contents($tempPath, $bodyContents);

            if (!file_exists($tempPath) || filesize($tempPath) !== $contentLength) {
                throw new \RuntimeException("Failed to write downloaded file to temp location");
            }

            return $tempPath;
        } catch (\Aws\S3\Exception\S3Exception $e) {
            Log::error('[ExtractMetadataJob] Failed to download asset from S3', [
                'bucket' => $bucket->name,
                'key' => $s3Key,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException("Failed to download asset from S3: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Find FFmpeg executable path.
     *
     * @return string|null Path to FFmpeg executable or null if not found
     */
    protected function findFFmpegPath(): ?string
    {
        $possiblePaths = [
            'ffmpeg',
            '/usr/bin/ffmpeg',
            '/usr/local/bin/ffmpeg',
            '/opt/homebrew/bin/ffmpeg',
        ];

        foreach ($possiblePaths as $path) {
            if ($path === 'ffmpeg') {
                $output = [];
                $returnCode = 0;
                exec('which ffmpeg 2>&1', $output, $returnCode);
                if ($returnCode === 0 && !empty($output[0]) && file_exists($output[0])) {
                    return $output[0];
                }
            } elseif (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Get video information using FFprobe.
     *
     * @param string $videoPath Path to video file
     * @param string $ffmpegPath Path to FFmpeg executable
     * @return array Video information (duration, width, height)
     * @throws \RuntimeException If FFprobe fails
     */
    protected function getVideoInfo(string $videoPath, string $ffmpegPath): array
    {
        $ffprobePath = str_replace('ffmpeg', 'ffprobe', $ffmpegPath);
        if (!file_exists($ffprobePath) || !is_executable($ffprobePath)) {
            $ffprobePath = $ffmpegPath;
        }

        $command = sprintf(
            '%s -v quiet -print_format json -show_format -show_streams %s 2>&1',
            escapeshellarg($ffprobePath),
            escapeshellarg($videoPath)
        );

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            $errorOutput = implode("\n", $output);
            throw new \RuntimeException("Failed to get video information: FFprobe returned error code {$returnCode}");
        }

        $jsonOutput = implode("\n", $output);
        $videoData = json_decode($jsonOutput, true);

        if (!$videoData || !isset($videoData['format'])) {
            throw new \RuntimeException('Failed to parse video information from FFprobe output');
        }

        $videoStream = null;
        foreach ($videoData['streams'] ?? [] as $stream) {
            if (isset($stream['codec_type']) && $stream['codec_type'] === 'video') {
                $videoStream = $stream;
                break;
            }
        }

        if (!$videoStream) {
            throw new \RuntimeException('No video stream found in file');
        }

        $duration = (float) ($videoData['format']['duration'] ?? 0);
        $width = (int) ($videoStream['width'] ?? 0);
        $height = (int) ($videoStream['height'] ?? 0);

        return [
            'duration' => $duration,
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $asset = Asset::find($this->assetId);

        if ($asset) {
            // Use centralized failure recording service
            app(AssetProcessingFailureService::class)->recordFailure(
                $asset,
                self::class,
                $exception,
                $this->attempts()
            );
        }
    }
}
