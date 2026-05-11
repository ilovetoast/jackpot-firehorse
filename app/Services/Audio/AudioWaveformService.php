<?php

namespace App\Services\Audio;

use App\Models\Asset;
use App\Services\TenantBucketService;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Log;

/**
 * Render a waveform PNG for an audio asset using FFmpeg's `showwavespic`
 * filter, then upload it to the canonical previews path so the thumbnail
 * pipeline (and AssetCard / lightbox) can render it identically to a
 * video poster.
 *
 * Output path (mirrors VIDEO_PREVIEW conventions):
 *   {basePath}previews/audio_waveform.png
 * where basePath = dirname(asset->storage_root_path).'/'.
 *
 * The path is also stored on the asset metadata as
 * `metadata.audio.waveform_path` so AssetVariantPathResolver / frontend
 * code does not need to guess. If FFmpeg is not installed we no-op
 * gracefully — the asset will fall back to a generic audio placeholder.
 */
class AudioWaveformService
{
    public function __construct(
        protected ?S3Client $s3Client = null,
        protected ?TenantBucketService $bucketService = null,
    ) {
        $this->bucketService ??= app(TenantBucketService::class);
        $this->s3Client ??= $this->bucketService->getS3Client();
    }

    /**
     * Generate the waveform PNG and persist it.
     *
     * @return array{success: bool, path?: string, reason?: string, error?: string}
     */
    public function generateForAsset(Asset $asset): array
    {
        $bucket = $asset->storageBucket;
        $sourceKey = $asset->storage_root_path;

        if (! $bucket || ! is_string($sourceKey) || $sourceKey === '') {
            return ['success' => false, 'reason' => 'missing_source'];
        }

        $ffmpeg = $this->findFFmpegPath();
        if ($ffmpeg === null) {
            Log::warning('[AudioWaveformService] FFmpeg not found — skipping waveform', [
                'asset_id' => $asset->id,
            ]);

            return ['success' => false, 'reason' => 'ffmpeg_not_found'];
        }

        $localSource = null;
        $localOutput = null;

        try {
            $localSource = $this->downloadSourceToTemp($bucket->name, $sourceKey);
            $localOutput = tempnam(sys_get_temp_dir(), 'audio_waveform_').'.png';
            if ($localOutput === false) {
                return ['success' => false, 'reason' => 'tempfile_failed'];
            }

            $width = 1024;
            $height = 256;
            $color = '0x6B7280'; // tailwind slate-500-ish; rendered against transparent bg

            // showwavespic produces a single-frame PNG of the entire waveform.
            // -filter_complex draws on a transparent canvas. -frames:v 1 keeps it to one frame.
            // -an strips audio (PNG output anyway). -y overwrite.
            $cmd = sprintf(
                '%s -y -hide_banner -loglevel error -i %s '.
                '-filter_complex "aformat=channel_layouts=mono,'.
                'showwavespic=s=%dx%d:colors=%s" '.
                '-frames:v 1 %s 2>&1',
                escapeshellarg($ffmpeg),
                escapeshellarg($localSource),
                $width,
                $height,
                $color,
                escapeshellarg($localOutput),
            );

            $output = [];
            $rc = 0;
            exec($cmd, $output, $rc);
            if ($rc !== 0 || ! file_exists($localOutput) || filesize($localOutput) === 0) {
                Log::warning('[AudioWaveformService] FFmpeg failed', [
                    'asset_id' => $asset->id,
                    'rc' => $rc,
                    'output' => implode("\n", $output),
                ]);

                return ['success' => false, 'reason' => 'ffmpeg_failed', 'error' => implode("\n", $output)];
            }

            $targetKey = $this->resolveWaveformPath($asset);
            if ($targetKey === '') {
                return ['success' => false, 'reason' => 'no_storage_root'];
            }

            $this->s3Client->putObject([
                'Bucket' => $bucket->name,
                'Key' => $targetKey,
                'Body' => fopen($localOutput, 'rb'),
                'ContentType' => 'image/png',
                'Metadata' => [
                    'asset-id' => (string) $asset->id,
                    'kind' => 'audio_waveform',
                ],
            ]);

            $metadata = $asset->metadata ?? [];
            if (! is_array($metadata)) {
                $metadata = [];
            }
            $metadata['audio'] = array_merge($metadata['audio'] ?? [], [
                'waveform_path' => $targetKey,
                'waveform_width' => $width,
                'waveform_height' => $height,
            ]);
            $asset->update(['metadata' => $metadata]);

            return ['success' => true, 'path' => $targetKey];
        } catch (\Throwable $e) {
            Log::error('[AudioWaveformService] Unexpected failure', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'reason' => 'exception', 'error' => $e->getMessage()];
        } finally {
            if ($localSource && file_exists($localSource)) {
                @unlink($localSource);
            }
            if ($localOutput && file_exists($localOutput)) {
                @unlink($localOutput);
            }
        }
    }

    protected function resolveWaveformPath(Asset $asset): string
    {
        $root = (string) ($asset->storage_root_path ?? '');
        if ($root === '') {
            return '';
        }
        $dir = dirname($root);
        if ($dir === '.' || $dir === '') {
            return '';
        }

        return rtrim($dir, '/').'/previews/audio_waveform.png';
    }

    protected function downloadSourceToTemp(string $bucketName, string $key): string
    {
        try {
            $result = $this->s3Client->getObject(['Bucket' => $bucketName, 'Key' => $key]);
            $body = (string) $result['Body'];
            if ($body === '') {
                throw new \RuntimeException('Empty audio source from S3');
            }
            $temp = tempnam(sys_get_temp_dir(), 'audio_src_');
            if ($temp === false || @file_put_contents($temp, $body) === false) {
                throw new \RuntimeException('Unable to write temp source');
            }

            return $temp;
        } catch (S3Exception $e) {
            throw new \RuntimeException("Failed to download audio source from S3: {$e->getMessage()}", 0, $e);
        }
    }

    protected function findFFmpegPath(): ?string
    {
        $candidates = ['ffmpeg', '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg'];
        foreach ($candidates as $path) {
            if ($path === 'ffmpeg') {
                $output = [];
                $rc = 0;
                @exec('which ffmpeg 2>/dev/null', $output, $rc);
                if ($rc === 0 && ! empty($output[0]) && file_exists($output[0])) {
                    return $output[0];
                }
            } elseif (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }
}
