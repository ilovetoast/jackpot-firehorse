<?php

namespace App\Jobs;

use Aws\S3\S3Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DeleteAvatarJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [30, 120, 600];

    public string $avatarPath;
    public ?string $bucket;

    public function __construct(string $avatarPath, ?string $bucket = null)
    {
        $this->avatarPath = $avatarPath;
        $this->bucket = $bucket;
    }

    public function handle(): void
    {
        if (str_starts_with($this->avatarPath, 's3://')) {
            $this->deleteFromS3(substr($this->avatarPath, 5));
        } elseif (str_starts_with($this->avatarPath, '/storage/')) {
            $this->deleteFromLocal();
        }
    }

    protected function deleteFromS3(string $key): void
    {
        $bucket = $this->bucket ?: config('storage.shared_bucket');

        if (! $bucket) {
            Log::warning('[DeleteAvatarJob] No bucket resolved, skipping', ['key' => $key]);
            return;
        }

        try {
            $s3 = $this->createS3Client();
            $s3->deleteObject([
                'Bucket' => $bucket,
                'Key' => $key,
            ]);

            Log::info('[DeleteAvatarJob] Deleted avatar from S3', [
                'bucket' => $bucket,
                'key' => $key,
            ]);
        } catch (\Throwable $e) {
            Log::error('[DeleteAvatarJob] S3 delete failed', [
                'bucket' => $bucket,
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    protected function deleteFromLocal(): void
    {
        $relativePath = str_replace('/storage/', '', $this->avatarPath);

        if (str_starts_with($relativePath, 'avatars/')) {
            Storage::disk('public')->delete($relativePath);
            Log::info('[DeleteAvatarJob] Deleted local avatar', ['path' => $relativePath]);
        }
    }

    protected function createS3Client(): S3Client
    {
        $config = [
            'version' => 'latest',
            'region' => config('storage.default_region', config('filesystems.disks.s3.region', 'us-east-1')),
        ];

        if (config('filesystems.disks.s3.key')) {
            $config['credentials'] = [
                'key' => config('filesystems.disks.s3.key'),
                'secret' => config('filesystems.disks.s3.secret'),
            ];
        }

        if (config('filesystems.disks.s3.endpoint')) {
            $config['endpoint'] = config('filesystems.disks.s3.endpoint');
            $config['use_path_style_endpoint'] = config('filesystems.disks.s3.use_path_style_endpoint', false);
        }

        return new S3Client($config);
    }
}
