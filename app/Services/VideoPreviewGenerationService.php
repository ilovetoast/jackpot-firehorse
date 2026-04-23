<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\StorageBucket;
use App\Support\EditorAssetOriginalBytesLoader;
use App\Support\VideoDisplayProbe;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Video Preview Generation Service
 *
 * Generates short, muted MP4 preview videos for hover / “quick” previews (asset grid + drawer).
 * Orientation matches static video posters: try FFmpeg default autorotation + scale first; on failure use
 * -noautorotate with {@see VideoDisplayProbe::ffmpegTransposeFilters()} (same idea as
 * {@see ThumbnailGenerationService::generateVideoThumbnail}).
 *
 * Preview specs:
 * - Duration: 2-4 seconds
 * - Resolution: ~320px width (maintains aspect ratio)
 * - FPS: 8-12
 * - Audio: none (muted)
 * - Codec: H.264
 * - Format: MP4
 *
 * The preview is extracted from the middle portion of the video to show
 * representative content rather than just the beginning.
 */
class VideoPreviewGenerationService
{
    /**
     * S3 client instance.
     */
    protected ?S3Client $s3Client = null;

    /**
     * Create a new VideoPreviewGenerationService instance.
     *
     * @param  S3Client|null  $s3Client  Optional S3 client for testing
     */
    public function __construct(
        ?S3Client $s3Client = null
    ) {
        $this->s3Client = $s3Client ?? $this->createS3Client();
    }

    /**
     * Download the asset's original video from tenant S3 to a temp file (caller must unlink).
     * Shared with other FFmpeg-based video workers (e.g. video AI frame sampling).
     */
    public function downloadSourceToTemp(Asset $asset): string
    {
        if (! $asset->storage_root_path) {
            throw new \RuntimeException('Asset missing storage path');
        }

        $asset->loadMissing('storageBucket');

        if ($asset->storageBucket !== null) {
            return $this->downloadFromS3($asset->storageBucket, $asset->storage_root_path);
        }

        // Studio outputs and some pipeline rows omit storage_bucket_id while the object still exists on
        // a configured disk — same resolution as {@see EditorAssetOriginalBytesLoader}.
        $bytes = EditorAssetOriginalBytesLoader::loadFromStorage($asset, null);
        $tempPath = tempnam(sys_get_temp_dir(), 'video_src_');
        if ($tempPath === false || @file_put_contents($tempPath, $bytes) === false) {
            throw new \RuntimeException('Failed to write downloaded video to temp file.');
        }

        return $tempPath;
    }

    /**
     * Generate preview video for an asset.
     *
     * Downloads the video from S3, extracts a short segment, encodes it as
     * an optimized preview, and uploads it back to S3.
     *
     * @param  Asset  $asset  The asset to generate preview for
     * @return string S3 key path for the preview video
     *
     * @throws \RuntimeException If preview generation fails
     */
    public function generatePreview(Asset $asset): string
    {
        if (! $asset->storage_root_path) {
            throw new \RuntimeException('Asset missing storage path');
        }

        $asset->loadMissing('storageBucket');
        $sourceS3Path = $asset->storage_root_path;
        $bucket = $asset->storageBucket;
        $fallbackDisk = $bucket === null
            ? EditorAssetOriginalBytesLoader::resolveFallbackDiskForObjectKey($asset, $sourceS3Path)
            : null;
        if ($bucket === null && $fallbackDisk === null) {
            throw new \RuntimeException(
                'Video source is not reachable: no storage_bucket_id on the asset and the file was not found on '
                .'configured disks ('.implode(', ', EditorAssetOriginalBytesLoader::fallbackDiskNamesInPriorityOrder()).'). '
                .'Confirm storage_root_path and deploy preview/thumbnail fallback fixes, or assign the tenant’s StorageBucket to this asset.'
            );
        }

        Log::info('[VideoPreviewGenerationService] Generating preview video', [
            'asset_id' => $asset->id,
            'source_s3_path' => $sourceS3Path,
            'bucket' => $bucket?->name,
            'fallback_disk' => $fallbackDisk,
        ]);

        // Download original video to temporary location
        $tempPath = $this->downloadSourceToTemp($asset);

        if (! file_exists($tempPath) || filesize($tempPath) === 0) {
            throw new \RuntimeException('Downloaded source video file is invalid or empty');
        }

        $encodedPreviewPath = null;
        try {
            // Get video information
            $ffmpegPath = $this->findFFmpegPath();
            if (! $ffmpegPath) {
                throw new \RuntimeException('FFmpeg is not installed or not found in PATH. Video processing requires FFmpeg.');
            }

            $videoInfo = $this->getVideoInfo($tempPath, $ffmpegPath);
            $duration = $videoInfo['duration'] ?? 0;
            $displayW = $videoInfo['display_width'] ?? 0;
            $displayH = $videoInfo['display_height'] ?? 0;
            $rotation = $videoInfo['rotation'] ?? 0;

            if ($duration <= 0) {
                throw new \RuntimeException('Unable to determine video duration');
            }

            if ($displayW === 0 || $displayH === 0) {
                throw new \RuntimeException('Unable to determine video dimensions');
            }

            Log::info('[VideoPreviewGenerationService] Video info extracted', [
                'asset_id' => $asset->id,
                'duration' => $duration,
                'coded_width' => $videoInfo['coded_width'] ?? null,
                'coded_height' => $videoInfo['coded_height'] ?? null,
                'display_width' => $displayW,
                'display_height' => $displayH,
                'rotation_deg' => $rotation,
            ]);

            // Keep DB in sync with display orientation (fixes admin UI + eligibility that use these columns).
            try {
                $asset->update([
                    'video_width' => $displayW,
                    'video_height' => $displayH,
                ]);
            } catch (\Throwable $e) {
                Log::warning('[VideoPreviewGenerationService] Could not persist display video dimensions', [
                    'asset_id' => $asset->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Calculate preview segment: 2-4 seconds from middle of video
            $previewDuration = min(4.0, max(2.0, min($duration, 4.0))); // 2-4 seconds, or full video if shorter
            $startTime = max(0, ($duration - $previewDuration) / 2); // Start from middle

            // Longest side capped ~320px after rotation is baked; keeps portrait and landscape correct
            $targetBox = 320;

            $encodedPreviewPath = $this->extractPreviewSegment(
                $tempPath,
                $ffmpegPath,
                $startTime,
                $previewDuration,
                $targetBox,
                (int) $rotation
            );

            $s3PreviewPath = $bucket !== null
                ? $this->uploadPreviewToS3($bucket, $asset, $encodedPreviewPath)
                : $this->uploadPreviewToFallbackDisk((string) $fallbackDisk, $asset, $encodedPreviewPath);

            Log::info('[VideoPreviewGenerationService] Preview video generated and uploaded', [
                'asset_id' => $asset->id,
                's3_path' => $s3PreviewPath,
                'preview_duration' => $previewDuration,
                'preview_max_box' => $targetBox,
            ]);

            return $s3PreviewPath;
        } finally {
            if (is_string($encodedPreviewPath) && $encodedPreviewPath !== '' && file_exists($encodedPreviewPath)) {
                @unlink($encodedPreviewPath);
            }
            // Clean up temporary file
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * Upload hover preview next to the original when there is no tenant {@see StorageBucket} model
     * (same relative key layout as {@see self::uploadPreviewToS3()}).
     */
    protected function uploadPreviewToFallbackDisk(string $diskName, Asset $asset, string $localPreviewPath): string
    {
        $assetPathInfo = pathinfo($asset->storage_root_path);
        $relativePreviewPath = "{$assetPathInfo['dirname']}/previews/video_preview.mp4";
        $body = file_get_contents($localPreviewPath);
        if ($body === false || $body === '') {
            throw new \RuntimeException('Empty preview file for upload');
        }
        try {
            Storage::disk($diskName)->put($relativePreviewPath, $body, ['visibility' => 'private']);
        } catch (\Throwable $e) {
            Log::error('[VideoPreviewGenerationService] Failed to upload preview to fallback disk', [
                'asset_id' => $asset->id,
                'disk' => $diskName,
                'key' => $relativePreviewPath,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException("Failed to upload preview video: {$e->getMessage()}", 0, $e);
        }

        return $relativePreviewPath;
    }

    /**
     * Extract preview segment from video.
     *
     * 🔒 HOVER MP4 ENCODE LOCK — Attempt order is paired with {@see ThumbnailGenerationService::generateVideoThumbnail}.
     *
     * @param  string  $sourcePath  Local path to source video
     * @param  string  $ffmpegPath  Path to FFmpeg executable
     * @param  float  $startTime  Start time in seconds
     * @param  float  $duration  Duration in seconds
     * @param  int  $targetBox  Max width and height of bounding box; video fits inside preserving aspect
     * @param  int  $rotationDeg  0/90/180/270 from ffprobe ({@see VideoDisplayProbe::dimensionsFromFfprobe})
     * @return string Path to generated preview video
     *
     * @throws \RuntimeException If extraction fails
     */
    protected function extractPreviewSegment(
        string $sourcePath,
        string $ffmpegPath,
        float $startTime,
        float $duration,
        int $targetBox,
        int $rotationDeg
    ): string {
        $previewPath = tempnam(sys_get_temp_dir(), 'video_preview_').'.mp4';

        // Match {@see ThumbnailGenerationService::generateVideoThumbnail}: try default input autorotation
        // first (scale-only vf), then -noautorotate + ffprobe-derived transpose so probe drift does not
        // “win” when FFmpeg would have oriented the clip correctly.
        $fps = 10; // 10 FPS for preview (smooth enough, smaller file)

        $vfScaleOnly = sprintf(
            'scale=%d:%d:force_original_aspect_ratio=decrease:flags=lanczos,setsar=1,fps=%d',
            $targetBox,
            $targetBox,
            $fps
        );

        $transpose = VideoDisplayProbe::ffmpegTransposeFilters($rotationDeg);
        $vfManual = implode(',', array_values(array_filter([
            $transpose !== '' ? $transpose : null,
            sprintf('scale=%d:%d:force_original_aspect_ratio=decrease:flags=lanczos', $targetBox, $targetBox),
            'setsar=1',
            sprintf('fps=%d', $fps),
        ])));

        $attempts = [
            [
                'label' => 'autorotate_scale_only',
                'cmd' => sprintf(
                    '%s -ss %.2f -i %s -t %.2f -vf %s -an -c:v libx264 -preset fast -crf 28 -movflags +faststart -y %s 2>&1',
                    escapeshellarg($ffmpegPath),
                    $startTime,
                    escapeshellarg($sourcePath),
                    $duration,
                    escapeshellarg($vfScaleOnly),
                    escapeshellarg($previewPath)
                ),
            ],
            [
                'label' => 'noautorotate_manual_rotation',
                'cmd' => sprintf(
                    '%s -ss %.2f -noautorotate -i %s -t %.2f -vf %s -an -c:v libx264 -preset fast -crf 28 -movflags +faststart -y %s 2>&1',
                    escapeshellarg($ffmpegPath),
                    $startTime,
                    escapeshellarg($sourcePath),
                    $duration,
                    escapeshellarg($vfManual),
                    escapeshellarg($previewPath)
                ),
            ],
        ];

        $lastOutput = '';
        $lastRc = -1;
        foreach ($attempts as $idx => $attempt) {
            if (file_exists($previewPath)) {
                @unlink($previewPath);
            }

            Log::info('[VideoPreviewGenerationService] Extracting preview segment', [
                'source_path' => $sourcePath,
                'start_time' => $startTime,
                'duration' => $duration,
                'target_box' => $targetBox,
                'rotation_deg' => $rotationDeg,
                'attempt' => $attempt['label'],
                'vf' => $idx === 0 ? $vfScaleOnly : $vfManual,
                'fps' => $fps,
            ]);

            $output = [];
            $returnCode = 0;
            exec($attempt['cmd'], $output, $returnCode);
            $lastOutput = implode("\n", $output);
            $lastRc = $returnCode;

            if ($returnCode === 0 && file_exists($previewPath) && filesize($previewPath) > 0) {
                Log::info('[VideoPreviewGenerationService] Preview segment extracted', [
                    'preview_path' => $previewPath,
                    'preview_size_bytes' => filesize($previewPath),
                    'attempt' => $attempt['label'],
                ]);

                return $previewPath;
            }

            if ($idx === 0) {
                Log::warning('[VideoPreviewGenerationService] Autorotate preview encode failed; retrying with -noautorotate + manual rotation', [
                    'source_path' => $sourcePath,
                    'return_code' => $returnCode,
                    'output_tail' => substr($lastOutput, -800),
                ]);
            }
        }

        if (file_exists($previewPath)) {
            @unlink($previewPath);
        }

        Log::error('[VideoPreviewGenerationService] FFmpeg preview extraction failed (all attempts)', [
            'source_path' => $sourcePath,
            'return_code' => $lastRc,
            'output' => $lastOutput,
        ]);

        throw new \RuntimeException("Failed to extract preview segment: FFmpeg returned error code {$lastRc}");
    }

    /**
     * Upload preview video to S3.
     *
     * @return string S3 key path for the preview
     *
     * @throws \RuntimeException If upload fails
     */
    protected function uploadPreviewToS3(
        StorageBucket $bucket,
        Asset $asset,
        string $localPreviewPath
    ): string {
        // Generate S3 path for preview
        // Pattern: {asset_path_base}/previews/video_preview.mp4
        $assetPathInfo = pathinfo($asset->storage_root_path);
        $s3PreviewPath = "{$assetPathInfo['dirname']}/previews/video_preview.mp4";

        try {
            $this->s3Client->putObject([
                'Bucket' => $bucket->name,
                'Key' => $s3PreviewPath,
                'Body' => file_get_contents($localPreviewPath),
                'ContentType' => 'video/mp4',
                'CacheControl' => 'public, max-age=0, must-revalidate',
                'Metadata' => [
                    'original-asset-id' => $asset->id,
                    'preview-type' => 'hover',
                    'generated-at' => now()->toIso8601String(),
                ],
            ]);

            return $s3PreviewPath;
        } catch (S3Exception $e) {
            Log::error('Failed to upload preview video to S3', [
                'bucket' => $bucket->name,
                'key' => $s3PreviewPath,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException("Failed to upload preview video to S3: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Download file from S3 to temporary location.
     *
     * @return string Path to temporary file
     *
     * @throws \RuntimeException If download fails
     */
    protected function downloadFromS3(StorageBucket $bucket, string $s3Key): string
    {
        try {
            Log::info('[VideoPreviewGenerationService] Downloading source video from S3', [
                'bucket' => $bucket->name,
                's3_key' => $s3Key,
            ]);

            $result = $this->s3Client->getObject([
                'Bucket' => $bucket->name,
                'Key' => $s3Key,
            ]);

            $body = $result['Body'];
            $bodyContents = (string) $body;
            $contentLength = strlen($bodyContents);

            if ($contentLength === 0) {
                throw new \RuntimeException('Downloaded file from S3 is empty (size: 0 bytes)');
            }

            $tempPath = tempnam(sys_get_temp_dir(), 'video_preview_');
            file_put_contents($tempPath, $bodyContents);

            if (! file_exists($tempPath) || filesize($tempPath) !== $contentLength) {
                throw new \RuntimeException('Failed to write downloaded file to temp location');
            }

            return $tempPath;
        } catch (S3Exception $e) {
            Log::error('[VideoPreviewGenerationService] Failed to download asset from S3', [
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
                if ($returnCode === 0 && ! empty($output[0]) && file_exists($output[0])) {
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
     * @param  string  $videoPath  Path to video file
     * @param  string  $ffmpegPath  Path to FFmpeg executable
     * @return array Video information (duration, width, height)
     *
     * @throws \RuntimeException If FFprobe fails
     */
    protected function getVideoInfo(string $videoPath, string $ffmpegPath): array
    {
        $ffprobePath = str_replace('ffmpeg', 'ffprobe', $ffmpegPath);
        if (! file_exists($ffprobePath) || ! is_executable($ffprobePath)) {
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

        if (! $videoData || ! isset($videoData['format'])) {
            throw new \RuntimeException('Failed to parse video information from FFprobe output');
        }

        $dims = VideoDisplayProbe::dimensionsFromFfprobe($videoData);

        if (! $dims) {
            throw new \RuntimeException('No video stream found in file');
        }

        $duration = (float) ($videoData['format']['duration'] ?? 0);

        return [
            'duration' => $duration,
            'coded_width' => $dims['width'],
            'coded_height' => $dims['height'],
            'display_width' => $dims['display_width'],
            'display_height' => $dims['display_height'],
            'rotation' => $dims['rotation'],
        ];
    }

    /**
     * Create S3 client instance.
     */
    protected function createS3Client(): S3Client
    {
        if (! class_exists(S3Client::class)) {
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

        return new S3Client($config);
    }
}
