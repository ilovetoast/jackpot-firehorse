<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Models\Tenant;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Copies object storage under tenants/{uuid}/assets/... for demo workspace cloning.
 */
class DemoTenantStorageCopyService
{
    public function copyAssetVersionPrefix(
        Tenant $sourceTenant,
        Tenant $destTenant,
        string $sourceAssetId,
        string $destAssetId,
        int $versionNumber,
    ): void {
        if (! $sourceTenant->uuid || ! $destTenant->uuid) {
            throw new \RuntimeException('Tenant UUID required for storage copy.');
        }

        $srcPrefix = 'tenants/'.$sourceTenant->uuid.'/assets/'.$sourceAssetId.'/v'.$versionNumber;
        $dstPrefix = 'tenants/'.$destTenant->uuid.'/assets/'.$destAssetId.'/v'.$versionNumber;

        if (App::environment('testing')) {
            $this->copyViaLaravelStorage($srcPrefix, $dstPrefix);

            return;
        }

        $bucket = config('filesystems.disks.s3.bucket');
        if (! is_string($bucket) || $bucket === '') {
            Log::warning('[DemoTenantStorageCopyService] S3 bucket not configured; skipping physical copy', [
                'src_prefix' => $srcPrefix,
                'dst_prefix' => $dstPrefix,
            ]);

            return;
        }

        $client = $this->makeS3Client();
        if ($client === null) {
            Log::warning('[DemoTenantStorageCopyService] S3 client unavailable; skipping physical copy', [
                'src_prefix' => $srcPrefix,
            ]);

            return;
        }

        $this->copyPrefixWithClient($client, $bucket, $srcPrefix, $dstPrefix);
    }

    private function copyViaLaravelStorage(string $srcPrefix, string $dstPrefix): void
    {
        $disk = Storage::disk('s3');
        foreach ($disk->allFiles($srcPrefix) as $key) {
            $suffix = str_starts_with($key, $srcPrefix.'/')
                ? substr($key, strlen($srcPrefix) + 1)
                : (str_starts_with($key, $srcPrefix) ? substr($key, strlen($srcPrefix)) : '');
            $suffix = ltrim($suffix, '/');
            $destKey = $suffix === '' ? $dstPrefix : $dstPrefix.'/'.$suffix;
            if ($disk->exists($key)) {
                $disk->copy($key, $destKey);
            }
        }
    }

    private function copyPrefixWithClient(S3Client $client, string $bucket, string $srcPrefix, string $dstPrefix): void
    {
        $token = null;
        do {
            $args = [
                'Bucket' => $bucket,
                'Prefix' => $srcPrefix.'/',
            ];
            if ($token !== null) {
                $args['ContinuationToken'] = $token;
            }
            $result = $client->listObjectsV2($args);
            foreach ($result['Contents'] ?? [] as $obj) {
                $key = $obj['Key'] ?? null;
                if (! is_string($key) || $key === '') {
                    continue;
                }
                if (! str_starts_with($key, $srcPrefix.'/')) {
                    continue;
                }
                $suffix = substr($key, strlen($srcPrefix) + 1);
                $destKey = $dstPrefix.'/'.$suffix;
                $client->copyObject([
                    'Bucket' => $bucket,
                    'CopySource' => rawurlencode($bucket.'/'.$key),
                    'Key' => $destKey,
                ]);
            }
            $token = $result['IsTruncated'] ?? false ? ($result['NextContinuationToken'] ?? null) : null;
        } while ($token);
    }

    private function makeS3Client(): ?S3Client
    {
        $cfg = config('filesystems.disks.s3', []);
        if (! is_array($cfg) || ($cfg['driver'] ?? '') !== 's3') {
            return null;
        }
        if (empty($cfg['key']) || empty($cfg['secret'])) {
            return null;
        }

        return new S3Client([
            'version' => 'latest',
            'region' => $cfg['region'] ?? 'us-east-1',
            'credentials' => [
                'key' => $cfg['key'],
                'secret' => $cfg['secret'],
            ],
        ]);
    }
}
