<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\StorageBucket;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Video Preview Generation Service
 *
 * Generates short, muted MP4 preview videos for hover previews in the asset grid.
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
     * @param S3Client|null $s3Client Optional S3 client for testing
     */
    public function __construct(
        ?S3Client $s3Client = null
    ) {
        $this->s3Client = $s3Client ?? $this->createS3Client();
    }

    /**
     * Generate preview video for an asset.
     *
     * Downloads the video from S3, extracts a short segment, encodes it as
     * an optimized preview, and uploads it back to S3.
     *
     * @param Asset $asset The asset to generate preview for
     * @return string S3 key path for the preview video
     * @throws \RuntimeException If preview generation fails
     */
    public function generatePreview(Asset $asset): string
    {
        if (!$asset->storage_root_path || !$asset->storageBucket) {
            throw new \RuntimeException('Asset missing storage path or bucket');
        }

        $bucket = $asset->storageBucket;
        $sourceS3Path = $asset->storage_root_path;

        Log::info('[VideoPreviewGenerationService] Generating preview video', [
            'asset_id' => $asset->id,
            'source_s3_path' => $sourceS3Path,
            'bucket' => $bucket->name,
        ]);

        // Download original video to temporary location
        $tempPath = $this->downloadFromS3($bucket, $sourceS3Path);

        if (!file_exists($tempPath) || filesize($tempPath) === 0) {
            throw new \RuntimeException('Downloaded source video file is invalid or empty');
        }

        try {
            // Get video information
            $ffmpegPath = $this->findFFmpegPath();
            if (!$ffmpegPath) {
                throw new \RuntimeException('FFmpeg is not installed or not found in PATH. Video processing requires FFmpeg.');
            }

            $videoInfo = $this->getVideoInfo($tempPath, $ffmpegPath);
            $duration = $videoInfo['duration'] ?? 0;
            $width = $videoInfo['width'] ?? 0;
            $height = $videoInfo['height'] ?? 0;

            if ($duration <= 0) {
                throw new \RuntimeException('Unable to determine video duration');
            }

            if ($width === 0 || $height === 0) {
                throw new \RuntimeException('Unable to determine video dimensions');
            }

            Log::info('[VideoPreviewGenerationService] Video info extracted', [
                'asset_id' => $asset->id,
                'duration' => $duration,
                'width' => $width,
                'height' => $height,
            ]);

            // Calculate preview segment: 2-4 seconds from middle of video
            $previewDuration = min(4.0, max(2.0, min($duration, 4.0))); // 2-4 seconds, or full video if shorter
            $startTime = max(0, ($duration - $previewDuration) / 2); // Start from middle

            // Calculate target resolution: ~320px width, maintain aspect ratio
            $targetWidth = 320;
            $aspectRatio = $height / $width;
            $targetHeight = (int) round($targetWidth * $aspectRatio);

            // Ensure height is even (required by H.264)
            if ($targetHeight % 2 !== 0) {
                $targetHeight += 1;
            }

            // Generate preview video
            $previewPath = $this->extractPreviewSegment(
                $tempPath,
                $ffmpegPath,
                $startTime,
                $previewDuration,
                $targetWidth,
                $targetHeight
            );

            // Upload preview to S3
            $s3PreviewPath = $this->uploadPreviewToS3(
                $bucket,
                $asset,
                $previewPath
            );

            Log::info('[VideoPreviewGenerationService] Preview video generated and uploaded', [
                'asset_id' => $asset->id,
                's3_path' => $s3PreviewPath,
                'preview_duration' => $previewDuration,
                'preview_resolution' => "{$targetWidth}x{$targetHeight}",
            ]);

            return $s3PreviewPath;
        } finally {
            // Clean up temporary file
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * Extract preview segment from video.
     *
     * @param string $sourcePath Local path to source video
     * @param string $ffmpegPath Path to FFmpeg executable
     * @param float $startTime Start time in seconds
     * @param float $duration Duration in seconds
     * @param int $targetWidth Target width in pixels
     * @param int $targetHeight Target height in pixels
     * @return string Path to generated preview video
     * @throws \RuntimeException If extraction fails
     */
    protected function extractPreviewSegment(
        string $sourcePath,
        string $ffmpegPath,
        float $startTime,
        float $duration,
        int $targetWidth,
        int $targetHeight
    ): string {
        $previewPath = tempnam(sys_get_temp_dir(), 'video_preview_') . '.mp4';

        // FFmpeg command to extract and encode preview
        // -ss: seek to start time
        // -i: input file
        // -t: duration
        // -vf: video filter (scale and fps)
        // -an: no audio
        // -c:v libx264: H.264 codec
        // -preset fast: encoding speed/quality balance
        // -crf 28: quality (lower is better, 28 is good for previews)
        // -movflags +faststart: optimize for web streaming
        // -y: overwrite output file
        $fps = 10; // 10 FPS for preview (smooth enough, smaller file)
        
        $command = sprintf(
            '%s -ss %.2f -i %s -t %.2f -vf "scale=%d:%d,fps=%d" -an -c:v libx264 -preset fast -crf 28 -movflags +faststart -y %s 2>&1',
            escapeshellarg($ffmpegPath),
            $startTime,
            escapeshellarg($sourcePath),
            $duration,
            $targetWidth,
            $targetHeight,
            $fps,
            escapeshellarg($previewPath)
        );

        Log::info('[VideoPreviewGenerationService] Extracting preview segment', [
            'source_path' => $sourcePath,
            'start_time' => $startTime,
            'duration' => $duration,
            'target_resolution' => "{$targetWidth}x{$targetHeight}",
            'fps' => $fps,
        ]);

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($previewPath) || filesize($previewPath) === 0) {
            $errorOutput = implode("\n", $output);
            Log::error('[VideoPreviewGenerationService] FFmpeg preview extraction failed', [
                'source_path' => $sourcePath,
                'return_code' => $returnCode,
                'output' => $errorOutput,
            ]);
            
            // Clean up failed output file
            if (file_exists($previewPath)) {
                @unlink($previewPath);
            }
            
            throw new \RuntimeException("Failed to extract preview segment: FFmpeg returned error code {$returnCode}");
        }

        Log::info('[VideoPreviewGenerationService] Preview segment extracted', [
            'preview_path' => $previewPath,
            'preview_size_bytes' => filesize($previewPath),
        ]);

        return $previewPath;
    }

    /**
     * Upload preview video to S3.
     *
     * @param StorageBucket $bucket
     * @param Asset $asset
     * @param string $localPreviewPath
     * @return string S3 key path for the preview
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
     * @param StorageBucket $bucket
     * @param string $s3Key
     * @return string Path to temporary file
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
                throw new \RuntimeException("Downloaded file from S3 is empty (size: 0 bytes)");
            }

            $tempPath = tempnam(sys_get_temp_dir(), 'video_preview_');
            file_put_contents($tempPath, $bodyContents);

            if (!file_exists($tempPath) || filesize($tempPath) !== $contentLength) {
                throw new \RuntimeException("Failed to write downloaded file to temp location");
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
     * Create S3 client instance.
     *
     * @return S3Client
     */
    protected function createS3Client(): S3Client
    {
        if (!class_exists(S3Client::class)) {
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
