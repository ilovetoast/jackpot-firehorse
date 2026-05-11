<?php

namespace App\Services\Audio;

use App\Models\Asset;
use App\Services\TenantBucketService;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Log;

/**
 * Run FFprobe against an audio asset's bytes and persist canonical metadata
 * (`duration_seconds`, `bitrate`, `sample_rate`, `channels`, `codec`) on
 * the asset under `metadata.audio.*`. Mirrors the structure used by the
 * video pipeline so the AssetCard / lightbox / search code only has to
 * learn one schema.
 */
class AudioMetadataService
{
    public function __construct(
        protected ?S3Client $s3Client = null,
        protected ?TenantBucketService $bucketService = null,
    ) {
        $this->bucketService ??= app(TenantBucketService::class);
        $this->s3Client ??= $this->bucketService->getS3Client();
    }

    /**
     * @return array{success: bool, data?: array<string, mixed>, reason?: string, error?: string}
     */
    public function extractForAsset(Asset $asset): array
    {
        $bucket = $asset->storageBucket;
        $sourceKey = $asset->storage_root_path;

        if (! $bucket || ! is_string($sourceKey) || $sourceKey === '') {
            return ['success' => false, 'reason' => 'missing_source'];
        }

        $ffprobe = $this->findFFprobePath();
        if ($ffprobe === null) {
            return ['success' => false, 'reason' => 'ffprobe_not_found'];
        }

        $localSource = null;
        try {
            $localSource = $this->downloadSourceToTemp($bucket->name, $sourceKey);

            $cmd = sprintf(
                '%s -v quiet -print_format json -show_format -show_streams %s',
                escapeshellarg($ffprobe),
                escapeshellarg($localSource),
            );
            $output = [];
            $rc = 0;
            exec($cmd, $output, $rc);
            if ($rc !== 0) {
                return ['success' => false, 'reason' => 'ffprobe_failed'];
            }

            $json = json_decode(implode("\n", $output), true);
            if (! is_array($json)) {
                return ['success' => false, 'reason' => 'ffprobe_invalid_json'];
            }

            $audio = $this->summarize($json);

            $metadata = $asset->metadata ?? [];
            if (! is_array($metadata)) {
                $metadata = [];
            }
            $metadata['audio'] = array_merge($metadata['audio'] ?? [], $audio);
            $asset->update(['metadata' => $metadata]);

            return ['success' => true, 'data' => $audio];
        } catch (\Throwable $e) {
            Log::warning('[AudioMetadataService] failure', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'reason' => 'exception', 'error' => $e->getMessage()];
        } finally {
            if ($localSource && file_exists($localSource)) {
                @unlink($localSource);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $probe
     * @return array<string, mixed>
     */
    protected function summarize(array $probe): array
    {
        $format = $probe['format'] ?? [];
        $stream = null;
        foreach ($probe['streams'] ?? [] as $s) {
            if (($s['codec_type'] ?? null) === 'audio') {
                $stream = $s;
                break;
            }
        }

        $duration = $format['duration'] ?? ($stream['duration'] ?? null);
        $bitrate = $format['bit_rate'] ?? ($stream['bit_rate'] ?? null);

        return [
            'duration_seconds' => is_numeric($duration) ? (float) $duration : null,
            'bitrate' => is_numeric($bitrate) ? (int) $bitrate : null,
            'sample_rate' => isset($stream['sample_rate']) && is_numeric($stream['sample_rate'])
                ? (int) $stream['sample_rate']
                : null,
            'channels' => isset($stream['channels']) && is_numeric($stream['channels'])
                ? (int) $stream['channels']
                : null,
            'channel_layout' => $stream['channel_layout'] ?? null,
            'codec' => $stream['codec_name'] ?? null,
            'codec_long' => $stream['codec_long_name'] ?? null,
            'format_name' => $format['format_name'] ?? null,
            'format_long_name' => $format['format_long_name'] ?? null,
            'tags' => is_array($format['tags'] ?? null) ? $format['tags'] : null,
        ];
    }

    protected function downloadSourceToTemp(string $bucketName, string $key): string
    {
        try {
            $result = $this->s3Client->getObject(['Bucket' => $bucketName, 'Key' => $key]);
            $body = (string) $result['Body'];
            if ($body === '') {
                throw new \RuntimeException('Empty audio source from S3');
            }
            $temp = tempnam(sys_get_temp_dir(), 'audio_meta_');
            if ($temp === false || @file_put_contents($temp, $body) === false) {
                throw new \RuntimeException('Unable to write temp source');
            }

            return $temp;
        } catch (S3Exception $e) {
            throw new \RuntimeException("Failed to download audio source from S3: {$e->getMessage()}", 0, $e);
        }
    }

    protected function findFFprobePath(): ?string
    {
        foreach (['ffprobe', '/usr/bin/ffprobe', '/usr/local/bin/ffprobe', '/opt/homebrew/bin/ffprobe'] as $path) {
            if ($path === 'ffprobe') {
                $output = [];
                $rc = 0;
                @exec('which ffprobe 2>/dev/null', $output, $rc);
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
