<?php

namespace App\Services\Audio;

use App\Models\Asset;
use App\Services\TenantBucketService;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Log;

/**
 * Produce a "web playback" MP3 derivative for an audio asset whenever the
 * original would be a poor browser-streaming experience — uncompressed
 * WAV, lossless FLAC, very large sources, or any non-MP3 source above
 * the configured size threshold. The derivative is a 128 kbps stereo MP3
 * stored alongside the asset's other previews, which the AssetController
 * exposes as the canonical playback URL when present.
 *
 * Decision policy ({@see decideStrategy()}):
 *   - Source codec / extension in `web_playback_force_codecs`     -> always transcode.
 *   - Source size >= `web_playback_min_source_bytes`              -> transcode.
 *   - Source already MP3 below the threshold                      -> skip
 *     (the original is fine, no point re-encoding identical audio).
 *
 * Output:
 *   {basePath}previews/audio_web.mp3
 * Persisted on the asset under `metadata.audio.web_playback_*`:
 *   - web_playback_path        (S3 key)
 *   - web_playback_size_bytes  (output filesize)
 *   - web_playback_bitrate_kbps
 *   - web_playback_codec       (always 'mp3')
 *   - web_playback_reason      (why we transcoded)
 *
 * The waveform thumbnail and FFprobe metadata services do not depend on
 * this output, so failure here is non-blocking — the frontend simply
 * falls back to the original delivery URL.
 */
class AudioPlaybackOptimizationService
{
    public function __construct(
        protected ?S3Client $s3Client = null,
        protected ?TenantBucketService $bucketService = null,
    ) {
        $this->bucketService ??= app(TenantBucketService::class);
        $this->s3Client ??= $this->bucketService->getS3Client();
    }

    /**
     * @return array{
     *     success: bool,
     *     skipped?: bool,
     *     path?: string,
     *     size_bytes?: int,
     *     bitrate_kbps?: int,
     *     reason?: string,
     *     error?: string
     * }
     */
    public function generateForAsset(Asset $asset): array
    {
        if (! (bool) config('assets.audio.optimize_for_browser', true)) {
            return ['success' => true, 'skipped' => true, 'reason' => 'disabled_by_config'];
        }

        $bucket = $asset->storageBucket;
        $sourceKey = (string) ($asset->storage_root_path ?? '');
        if (! $bucket || $sourceKey === '') {
            return ['success' => false, 'reason' => 'missing_source'];
        }

        $strategy = $this->decideStrategy($asset);
        if ($strategy['action'] === 'skip') {
            return ['success' => true, 'skipped' => true, 'reason' => $strategy['reason']];
        }

        $ffmpeg = $this->findFFmpegPath();
        if ($ffmpeg === null) {
            Log::warning('[AudioPlaybackOptimizationService] FFmpeg not found — skipping web derivative', [
                'asset_id' => $asset->id,
            ]);

            return ['success' => false, 'reason' => 'ffmpeg_not_found'];
        }

        $localSource = null;
        $localOutput = null;
        try {
            $localSource = $this->downloadSourceToTemp($bucket->name, $sourceKey);
            $localOutput = tempnam(sys_get_temp_dir(), 'audio_web_').'.mp3';
            if ($localOutput === false) {
                return ['success' => false, 'reason' => 'tempfile_failed'];
            }

            $bitrate = max(32, (int) config('assets.audio.web_playback_bitrate_kbps', 128));
            $sampleRate = max(8000, (int) config('assets.audio.web_playback_sample_rate_hz', 44100));
            $channels = max(1, min(2, (int) config('assets.audio.web_playback_channels', 2)));

            // libmp3lame is preinstalled with ffmpeg in our worker image. We
            // explicitly normalize to a single, predictable output regardless
            // of the source container/codec so the browser sees `.mp3` and a
            // tight set of decode parameters.
            $cmd = sprintf(
                '%s -y -hide_banner -loglevel error -i %s -vn '.
                '-c:a libmp3lame -b:a %dk -ar %d -ac %d %s 2>&1',
                escapeshellarg($ffmpeg),
                escapeshellarg($localSource),
                $bitrate,
                $sampleRate,
                $channels,
                escapeshellarg($localOutput),
            );

            $output = [];
            $rc = 0;
            exec($cmd, $output, $rc);
            if ($rc !== 0 || ! file_exists($localOutput) || filesize($localOutput) === 0) {
                Log::warning('[AudioPlaybackOptimizationService] FFmpeg failed', [
                    'asset_id' => $asset->id,
                    'rc' => $rc,
                    'output' => implode("\n", array_slice($output, 0, 20)),
                ]);

                return ['success' => false, 'reason' => 'ffmpeg_failed', 'error' => implode("\n", $output)];
            }

            $targetKey = $this->resolveDerivativePath($asset);
            if ($targetKey === '') {
                return ['success' => false, 'reason' => 'no_storage_root'];
            }

            $this->s3Client->putObject([
                'Bucket' => $bucket->name,
                'Key' => $targetKey,
                'Body' => fopen($localOutput, 'rb'),
                'ContentType' => 'audio/mpeg',
                'Metadata' => [
                    'asset-id' => (string) $asset->id,
                    'kind' => 'audio_web_playback',
                    'reason' => $strategy['reason'],
                ],
            ]);

            $sizeBytes = (int) (filesize($localOutput) ?: 0);

            $metadata = $asset->metadata ?? [];
            if (! is_array($metadata)) {
                $metadata = [];
            }
            $metadata['audio'] = array_merge($metadata['audio'] ?? [], [
                'web_playback_path' => $targetKey,
                'web_playback_size_bytes' => $sizeBytes,
                'web_playback_bitrate_kbps' => $bitrate,
                'web_playback_codec' => 'mp3',
                'web_playback_reason' => $strategy['reason'],
                'web_playback_generated_at' => now()->toIso8601String(),
            ]);
            $asset->update(['metadata' => $metadata]);

            return [
                'success' => true,
                'path' => $targetKey,
                'size_bytes' => $sizeBytes,
                'bitrate_kbps' => $bitrate,
                'reason' => $strategy['reason'],
            ];
        } catch (\Throwable $e) {
            Log::error('[AudioPlaybackOptimizationService] unexpected failure', [
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

    /**
     * Decide whether the asset needs a transcoded web derivative and why.
     * Public so jobs/tests can reason about the choice without re-encoding.
     *
     * @return array{action: 'skip'|'transcode', reason: string}
     */
    public function decideStrategy(Asset $asset): array
    {
        $sizeBytes = (int) ($asset->size_bytes ?? 0);
        if ($sizeBytes <= 0) {
            // Fallback: try metadata.audio.size, else fall through to format-only.
            $sizeBytes = (int) ($asset->metadata['audio']['size_bytes'] ?? 0);
        }

        $forceCodecs = array_map('strtolower', (array) config('assets.audio.web_playback_force_codecs', []));
        $codec = strtolower((string) ($asset->metadata['audio']['codec'] ?? ''));
        $ext = strtolower((string) pathinfo((string) ($asset->original_filename ?? ''), PATHINFO_EXTENSION));

        if ($codec !== '' && in_array($codec, $forceCodecs, true)) {
            return ['action' => 'transcode', 'reason' => "force_codec:{$codec}"];
        }
        if ($ext !== '' && in_array($ext, $forceCodecs, true)) {
            return ['action' => 'transcode', 'reason' => "force_extension:{$ext}"];
        }

        $minBytes = (int) config('assets.audio.web_playback_min_source_bytes', 5 * 1024 * 1024);
        if ($sizeBytes > 0 && $sizeBytes >= $minBytes) {
            return ['action' => 'transcode', 'reason' => 'large_source'];
        }

        // Already MP3 and under threshold — original is the canonical playback file.
        $mime = strtolower((string) ($asset->mime_type ?? ''));
        if ($mime === 'audio/mpeg' || $mime === 'audio/mp3' || $codec === 'mp3' || $ext === 'mp3') {
            return ['action' => 'skip', 'reason' => 'mp3_under_threshold'];
        }

        // Non-MP3 source under the size threshold (small AAC/M4A/OGG): the browser
        // can play these directly. Skip to save storage + transcode time.
        return ['action' => 'skip', 'reason' => 'small_browser_compatible'];
    }

    protected function resolveDerivativePath(Asset $asset): string
    {
        $root = (string) ($asset->storage_root_path ?? '');
        if ($root === '') {
            return '';
        }
        $dir = dirname($root);
        if ($dir === '.' || $dir === '') {
            return '';
        }

        return rtrim($dir, '/').'/previews/audio_web.mp3';
    }

    protected function downloadSourceToTemp(string $bucketName, string $key): string
    {
        try {
            $result = $this->s3Client->getObject(['Bucket' => $bucketName, 'Key' => $key]);
            $body = (string) $result['Body'];
            if ($body === '') {
                throw new \RuntimeException('Empty audio source from S3');
            }
            $temp = tempnam(sys_get_temp_dir(), 'audio_web_src_');
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
        foreach (['ffmpeg', '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg'] as $path) {
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
