<?php

namespace App\Services\Audio;

use App\Models\Asset;
use App\Services\TenantBucketService;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Log;

/**
 * Decide which bytes to send to a speech-to-text provider for a given
 * audio asset, and produce a Whisper-friendly file when the source
 * (or the persisted web derivative) won't fit the API's 25 MB cap or
 * isn't in an accepted codec.
 *
 * Decision policy ({@see chooseSource()}):
 *   1. If the asset has a `metadata.audio.web_playback_path` derivative
 *      and that file is small enough for Whisper, use it as-is. This
 *      avoids re-encoding the same audio twice.
 *   2. Else if the original is small enough AND in a Whisper-accepted
 *      codec, use the original.
 *   3. Else transcode the original to a 32 kbps mono MP3 specifically
 *      for AI ingest. The output is ephemeral — caller deletes after
 *      upload.
 *
 * The third path is the "heavy" branch: long podcasts and lossless masters
 * land here. ProcessAssetJob already gates audio AI behind the dedicated
 * `ai` queue (see {@see \App\Jobs\RunAudioAiAnalysisJob}) so this service
 * never runs on a thumbnail worker.
 */
class AudioAiPreparationService
{
    public function __construct(
        protected ?S3Client $s3Client = null,
        protected ?TenantBucketService $bucketService = null,
    ) {
        $this->bucketService ??= app(TenantBucketService::class);
        $this->s3Client ??= $this->bucketService->getS3Client();
    }

    /**
     * Produce a local file path suitable for posting to Whisper. Caller is
     * responsible for `unlink()` after the API call (we mark `temporary => true`
     * for those cases so callers can decide whether to keep them).
     *
     * @return array{
     *     success: bool,
     *     path?: string,
     *     temporary?: bool,
     *     reason?: string,
     *     decision?: string,
     *     size_bytes?: int,
     *     error?: string
     * }
     */
    public function prepareLocalFile(Asset $asset): array
    {
        $bucket = $asset->storageBucket;
        if (! $bucket) {
            return ['success' => false, 'reason' => 'missing_bucket'];
        }

        $decision = $this->chooseSource($asset);

        $sourceKey = $decision['source_key'] ?? null;
        if (! is_string($sourceKey) || $sourceKey === '') {
            return ['success' => false, 'reason' => 'missing_source_key'];
        }

        try {
            $local = $this->downloadToTemp($bucket->name, $sourceKey, $decision['source_kind']);
        } catch (\Throwable $e) {
            Log::warning('[AudioAiPreparationService] download failed', [
                'asset_id' => $asset->id,
                'kind' => $decision['source_kind'],
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'reason' => 'download_failed', 'error' => $e->getMessage()];
        }

        if ($decision['action'] === 'use_as_is') {
            return [
                'success' => true,
                'path' => $local,
                'temporary' => true,
                'decision' => $decision['source_kind'],
                'reason' => $decision['reason'] ?? null,
                'size_bytes' => (int) (filesize($local) ?: 0),
            ];
        }

        // action === 'transcode'
        $ffmpeg = $this->findFFmpegPath();
        if ($ffmpeg === null) {
            @unlink($local);

            return ['success' => false, 'reason' => 'ffmpeg_not_found'];
        }

        $output = tempnam(sys_get_temp_dir(), 'audio_ai_').'.mp3';
        if ($output === false) {
            @unlink($local);

            return ['success' => false, 'reason' => 'tempfile_failed'];
        }

        $bitrate = max(16, (int) config('assets.audio.ai_prep.bitrate_kbps', 32));
        $sampleRate = max(8000, (int) config('assets.audio.ai_prep.sample_rate_hz', 16000));

        // Mono, low bitrate, narrow sample rate — speech recognition only
        // needs the voice band. This routinely shrinks long files 10-20x.
        $cmd = sprintf(
            '%s -y -hide_banner -loglevel error -i %s -vn '.
            '-c:a libmp3lame -b:a %dk -ar %d -ac 1 %s 2>&1',
            escapeshellarg($ffmpeg),
            escapeshellarg($local),
            $bitrate,
            $sampleRate,
            escapeshellarg($output),
        );

        $stdout = [];
        $rc = 0;
        exec($cmd, $stdout, $rc);

        // Always remove the temp source — the new MP3 is what we'll upload.
        @unlink($local);

        if ($rc !== 0 || ! file_exists($output) || filesize($output) === 0) {
            @unlink($output);
            Log::warning('[AudioAiPreparationService] FFmpeg failed', [
                'asset_id' => $asset->id,
                'rc' => $rc,
                'output' => implode("\n", array_slice($stdout, 0, 20)),
            ]);

            return ['success' => false, 'reason' => 'ffmpeg_failed', 'error' => implode("\n", $stdout)];
        }

        $sizeBytes = (int) (filesize($output) ?: 0);
        $whisperMax = (int) config('assets.audio.ai_prep.whisper_max_bytes', 24 * 1024 * 1024);
        if ($whisperMax > 0 && $sizeBytes > $whisperMax) {
            // Even at 32 kbps mono the file is still too big — the asset is
            // very long. We surface a clean reason so callers can decide
            // whether to chunk (out of scope here) or skip gracefully.
            @unlink($output);

            return [
                'success' => false,
                'reason' => 'oversized_after_transcode',
                'decision' => 'transcoded_for_ai',
                'size_bytes' => $sizeBytes,
            ];
        }

        return [
            'success' => true,
            'path' => $output,
            'temporary' => true,
            'decision' => 'transcoded_for_ai',
            'reason' => $decision['reason'] ?? null,
            'size_bytes' => $sizeBytes,
        ];
    }

    /**
     * Choose which on-disk source to feed to the AI provider, without doing
     * any I/O. Public for easy unit testing.
     *
     * @return array{
     *     action: 'use_as_is'|'transcode',
     *     source_kind: 'web_derivative'|'original',
     *     source_key: string|null,
     *     reason: string,
     *     accepted_codec: bool
     * }
     */
    public function chooseSource(Asset $asset): array
    {
        $whisperMax = (int) config('assets.audio.ai_prep.whisper_max_bytes', 24 * 1024 * 1024);
        $accepted = array_map('strtolower', (array) config('assets.audio.ai_prep.whisper_accepted_codecs', []));

        $originalKey = (string) ($asset->storage_root_path ?? '');
        $originalSize = (int) ($asset->size_bytes ?? 0);
        $originalCodec = strtolower((string) ($asset->metadata['audio']['codec'] ?? ''));
        $originalExt = strtolower((string) pathinfo((string) ($asset->original_filename ?? ''), PATHINFO_EXTENSION));
        $originalAcceptedCodec = $this->codecMatches($originalCodec, $originalExt, $accepted);

        $webKey = (string) ($asset->metadata['audio']['web_playback_path'] ?? '');
        $webSize = (int) ($asset->metadata['audio']['web_playback_size_bytes'] ?? 0);

        // Prefer the persisted web derivative when present — it's MP3 (always
        // accepted) and typically much smaller.
        if ($webKey !== '' && $webSize > 0 && $webSize <= $whisperMax) {
            return [
                'action' => 'use_as_is',
                'source_kind' => 'web_derivative',
                'source_key' => $webKey,
                'reason' => 'web_derivative_fits',
                'accepted_codec' => true,
            ];
        }

        // Original is small enough AND in an accepted codec — straight pass-through.
        if ($originalKey !== '' && $originalSize > 0 && $originalSize <= $whisperMax && $originalAcceptedCodec) {
            return [
                'action' => 'use_as_is',
                'source_kind' => 'original',
                'source_key' => $originalKey,
                'reason' => 'original_fits',
                'accepted_codec' => true,
            ];
        }

        // Need to transcode. Pick the smaller of web/original as the input.
        $useWebInput = $webKey !== '' && $webSize > 0
            && ($originalSize <= 0 || $webSize < $originalSize);

        return [
            'action' => 'transcode',
            'source_kind' => $useWebInput ? 'web_derivative' : 'original',
            'source_key' => $useWebInput ? $webKey : ($originalKey !== '' ? $originalKey : null),
            'reason' => $this->transcodeReason($originalSize, $whisperMax, $originalAcceptedCodec),
            'accepted_codec' => false,
        ];
    }

    protected function transcodeReason(int $originalSize, int $whisperMax, bool $accepted): string
    {
        if (! $accepted) {
            return 'codec_not_accepted';
        }
        if ($whisperMax > 0 && $originalSize > $whisperMax) {
            return 'oversized_for_whisper';
        }

        return 'fallback_transcode';
    }

    /**
     * @param  array<int, string>  $accepted
     */
    protected function codecMatches(string $codec, string $extension, array $accepted): bool
    {
        if ($codec !== '' && in_array($codec, $accepted, true)) {
            return true;
        }
        if ($extension !== '' && in_array($extension, $accepted, true)) {
            return true;
        }

        return false;
    }

    protected function downloadToTemp(string $bucketName, string $key, string $kind): string
    {
        try {
            $result = $this->s3Client->getObject(['Bucket' => $bucketName, 'Key' => $key]);
            $body = (string) $result['Body'];
            if ($body === '') {
                throw new \RuntimeException('Empty audio source from S3');
            }
            $ext = pathinfo($key, PATHINFO_EXTENSION) ?: 'bin';
            $temp = tempnam(sys_get_temp_dir(), 'audio_ai_in_').'.'.$ext;
            if ($temp === false || @file_put_contents($temp, $body) === false) {
                throw new \RuntimeException('Unable to write temp source');
            }

            return $temp;
        } catch (S3Exception $e) {
            throw new \RuntimeException("Failed to download audio source ({$kind}) from S3: {$e->getMessage()}", 0, $e);
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
