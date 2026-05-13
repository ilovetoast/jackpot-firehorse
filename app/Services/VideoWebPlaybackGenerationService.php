<?php

namespace App\Services;

use App\Models\Asset;
use App\Support\EditorAssetOriginalBytesLoader;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Full-length browser MP4 (H.264 / AAC) for {@see \App\Support\AssetVariant::VIDEO_WEB}.
 * Failures are non-fatal to the main asset pipeline.
 */
final class VideoWebPlaybackGenerationService
{
    protected ?S3Client $s3Client = null;

    public function __construct(
        ?S3Client $s3Client = null,
        protected ?VideoPreviewGenerationService $videoPreview = null,
    ) {
        $this->s3Client = $s3Client ?? $this->createS3Client();
        $this->videoPreview ??= app(VideoPreviewGenerationService::class);
    }

    /**
     * @return array{success: bool, path?: string, size_bytes?: int, error?: string}
     */
    public function transcodeAndStore(Asset $asset, array $decision): array
    {
        if (! ($decision['should_generate'] ?? false)) {
            return ['success' => false, 'error' => 'decision_skip'];
        }

        $asset->loadMissing('storageBucket');
        if (! $asset->storage_root_path) {
            return ['success' => false, 'error' => 'missing_storage_root_path'];
        }

        $ffmpeg = $this->findFFmpegPath();
        if ($ffmpeg === null) {
            return ['success' => false, 'error' => 'ffmpeg_not_found'];
        }

        $destKey = $this->canonicalOutputKey($asset);
        if ($destKey === '') {
            return ['success' => false, 'error' => 'invalid_output_key'];
        }

        $sourceTemp = null;
        $outputTemp = null;

        try {
            $sourceTemp = $this->videoPreview->downloadSourceToTemp($asset);
            if (! is_file($sourceTemp) || filesize($sourceTemp) === 0) {
                return ['success' => false, 'error' => 'empty_source_download'];
            }

            $outputTemp = tempnam(sys_get_temp_dir(), 'video_web_');
            if ($outputTemp === false) {
                return ['success' => false, 'error' => 'tempfile_failed'];
            }
            @unlink($outputTemp);
            $outputTemp .= '.mp4';

            $this->runFfmpegTranscode($ffmpeg, $sourceTemp, $outputTemp);

            if (! is_file($outputTemp) || filesize($outputTemp) < 1024) {
                return ['success' => false, 'error' => 'output_missing_or_too_small'];
            }

            $this->uploadOutput($asset, $outputTemp, $destKey);
            $this->verifyUploadedObject($asset, $destKey);

            $size = (int) filesize($outputTemp);

            $this->mergeVideoMetadata($asset, [
                'web_playback_status' => 'ready',
                'web_playback_path' => $destKey,
                'web_playback_size_bytes' => $size,
                'web_playback_strategy' => 'transcode',
                'web_playback_reason' => (string) ($decision['reason'] ?? 'forced_extension'),
                'web_playback_codec' => 'h264_aac_mp4',
                'web_playback_generated_at' => now()->toIso8601String(),
            ]);

            return ['success' => true, 'path' => $destKey, 'size_bytes' => $size];
        } catch (\Throwable $e) {
            Log::warning('[VideoWebPlaybackGenerationService] transcode failed', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => Str::limit($e->getMessage(), 400)];
        } finally {
            if (is_string($sourceTemp) && is_file($sourceTemp)) {
                @unlink($sourceTemp);
            }
            if (is_string($outputTemp) && is_file($outputTemp)) {
                @unlink($outputTemp);
            }
        }
    }

    public function mergeSkippedMetadata(Asset $asset, array $decision): void
    {
        $this->mergeVideoMetadata($asset, [
            'web_playback_status' => 'skipped',
            'web_playback_strategy' => (string) ($decision['strategy'] ?? 'native_skipped'),
            'web_playback_reason' => (string) ($decision['reason'] ?? 'unknown'),
        ]);
    }

    public function mergeFailedMetadata(Asset $asset, string $message): void
    {
        $this->mergeVideoMetadata($asset, [
            'web_playback_status' => 'failed',
            'web_playback_reason' => Str::limit($message, 500),
        ]);
    }

    protected function mergeVideoMetadata(Asset $asset, array $patch): void
    {
        $meta = $asset->metadata ?? [];
        $video = is_array($meta['video'] ?? null) ? $meta['video'] : [];
        $meta['video'] = array_merge($video, $patch);
        $asset->update(['metadata' => $meta]);
    }

    protected function canonicalOutputKey(Asset $asset): string
    {
        $root = (string) ($asset->storage_root_path ?? '');
        if ($root === '') {
            return '';
        }
        $dir = dirname($root);

        return ($dir === '.' ? '' : $dir).'/previews/video_web.mp4';
    }

    protected function runFfmpegTranscode(string $ffmpeg, string $input, string $output): void
    {
        $max = max(256, (int) config('assets.video.web_playback.max_dimension', 1920));
        $vbit = (string) config('assets.video.web_playback.video_bitrate', '4000k');
        $abit = (string) config('assets.video.web_playback.audio_bitrate', '128k');
        $preset = (string) config('assets.video.web_playback.x264_preset', 'veryfast');

        $hasAudio = $this->sourceHasAudioStream($input, $ffmpeg);

        $args = [
            $ffmpeg,
            '-nostdin',
            '-hide_banner',
            '-loglevel',
            'error',
            '-i',
            $input,
            '-map',
            '0:v:0',
        ];
        if ($hasAudio) {
            array_push($args, '-map', '0:a:0');
        }
        array_push(
            $args,
            '-vf',
            "scale=min($max,iw):-2",
            '-c:v',
            'libx264',
            '-pix_fmt',
            'yuv420p',
            '-preset',
            $preset,
            '-b:v',
            $vbit,
            '-movflags',
            '+faststart',
        );
        if ($hasAudio) {
            array_push($args, '-c:a', 'aac', '-b:a', $abit);
        } else {
            $args[] = '-an';
        }
        $args[] = '-y';
        $args[] = $output;

        $cmd = implode(' ', array_map('escapeshellarg', $args));
        $outputLines = [];
        $returnCode = 0;
        exec($cmd.' 2>&1', $outputLines, $returnCode);

        if ($returnCode !== 0 || ! is_file($output)) {
            $tail = Str::limit(implode("\n", $outputLines), 2000);
            throw new \RuntimeException('ffmpeg_transcode_failed: '.$tail);
        }
    }

    protected function sourceHasAudioStream(string $videoPath, string $ffmpegPath): bool
    {
        $ffprobePath = str_replace('ffmpeg', 'ffprobe', $ffmpegPath);
        if (! is_file($ffprobePath) || ! is_executable($ffprobePath)) {
            $ffprobePath = $ffmpegPath;
        }

        $cmd = implode(' ', [
            escapeshellarg($ffprobePath),
            '-v', 'quiet',
            '-select_streams', 'a',
            '-show_entries', 'stream=index',
            '-of', 'csv=p=0',
            escapeshellarg($videoPath),
        ]);
        $out = [];
        $code = 0;
        exec($cmd.' 2>&1', $out, $code);

        return $code === 0 && trim(implode('', $out)) !== '';
    }

    protected function uploadOutput(Asset $asset, string $localPath, string $destKey): void
    {
        $bucket = $asset->storageBucket;
        $body = file_get_contents($localPath);
        if ($body === false || $body === '') {
            throw new \RuntimeException('read_output_failed');
        }

        if ($bucket !== null) {
            try {
                $this->s3Client->putObject([
                    'Bucket' => $bucket->name,
                    'Key' => $destKey,
                    'Body' => $body,
                    'ContentType' => 'video/mp4',
                    'CacheControl' => 'public, max-age=0, must-revalidate',
                    'Metadata' => [
                        'original-asset-id' => (string) $asset->id,
                        'derivative-type' => 'video_web_full',
                        'generated-at' => now()->toIso8601String(),
                    ],
                ]);
            } catch (S3Exception $e) {
                throw new \RuntimeException('s3_put_failed: '.$e->getMessage(), 0, $e);
            }

            return;
        }

        $disk = EditorAssetOriginalBytesLoader::resolveFallbackDiskForObjectKey($asset, $asset->storage_root_path);
        if ($disk === null) {
            throw new \RuntimeException('no_storage_bucket_or_fallback_disk');
        }
        Storage::disk($disk)->put($destKey, $body, ['visibility' => 'private']);
    }

    protected function verifyUploadedObject(Asset $asset, string $key): void
    {
        $bucket = $asset->storageBucket;
        if ($bucket !== null) {
            $result = $this->s3Client->headObject([
                'Bucket' => $bucket->name,
                'Key' => $key,
            ]);
            $len = (int) ($result['ContentLength'] ?? 0);
            if ($len < 1024) {
                throw new \RuntimeException('uploaded_object_too_small');
            }

            return;
        }

        $disk = EditorAssetOriginalBytesLoader::resolveFallbackDiskForObjectKey($asset, $asset->storage_root_path);
        if ($disk === null || ! Storage::disk($disk)->exists($key)) {
            throw new \RuntimeException('uploaded_object_missing_on_fallback_disk');
        }
    }

    protected function findFFmpegPath(): ?string
    {
        foreach (['ffmpeg', '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg'] as $path) {
            if ($path === 'ffmpeg') {
                $output = [];
                $returnCode = 0;
                exec('which ffmpeg 2>&1', $output, $returnCode);
                if ($returnCode === 0 && ! empty($output[0]) && is_file($output[0])) {
                    return $output[0];
                }
            } elseif (is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    protected function createS3Client(): S3Client
    {
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
