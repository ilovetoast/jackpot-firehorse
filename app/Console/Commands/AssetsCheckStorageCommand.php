<?php

namespace App\Console\Commands;

use App\Models\Asset;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use Illuminate\Console\Command;

/**
 * Diagnose asset storage for thumbnail/S3 download failures.
 *
 * Use when GenerateThumbnailsJob fails with storage_error at downloadFromS3().
 * Verifies the asset's source file exists in S3 and reports any access issues.
 *
 * Usage:
 *   php artisan assets:check-storage 019c68b2-301f-71a7-a955-b9160990cc65
 *   php artisan assets:check-storage {asset_id}
 */
class AssetsCheckStorageCommand extends Command
{
    protected $signature = 'assets:check-storage {asset : Asset ID (UUID or numeric)}';

    protected $description = 'Diagnose asset storage (S3 path, bucket, object existence) for thumbnail download failures';

    public function handle(): int
    {
        $assetId = $this->argument('asset');

        $asset = Asset::with('storageBucket', 'tenant')->find($assetId);
        if (!$asset) {
            $this->error("Asset not found: {$assetId}");
            return Command::FAILURE;
        }

        $this->info("Asset: {$asset->id}");
        $this->line("  Title: " . ($asset->title ?? $asset->original_filename ?? '—'));
        $this->line("  MIME: " . ($asset->mime_type ?? '—'));
        $this->line("  Tenant: " . ($asset->tenant?->name ?? $asset->tenant_id ?? '—'));
        $this->line("  storage_root_path: " . ($asset->storage_root_path ?? '(null)'));
        $this->line("  storage_bucket_id: " . ($asset->storage_bucket_id ?? '(null)'));
        $this->newLine();

        if (!$asset->storage_root_path) {
            $this->error('Asset has no storage_root_path. Upload may have failed or path was never set.');
            return Command::FAILURE;
        }

        $bucket = $asset->storageBucket;
        if (!$bucket) {
            $this->error('Asset has no storage bucket (storage_bucket_id points to missing/invalid bucket).');
            return Command::FAILURE;
        }

        $this->info("Bucket: {$bucket->name}");
        $this->line("  Region: " . ($bucket->region ?? config('filesystems.disks.s3.region', 'us-east-1')));
        $this->newLine();

        $s3Client = $this->getS3Client();
        $key = $asset->storage_root_path;

        $this->info('Checking S3 object existence...');
        try {
            $exists = $s3Client->doesObjectExist($bucket->name, $key);
            if ($exists) {
                $this->info('✓ Object EXISTS in S3.');
                try {
                    $result = $s3Client->headObject([
                        'Bucket' => $bucket->name,
                        'Key' => $key,
                    ]);
                    $size = $result['ContentLength'] ?? 0;
                    $this->line("  Size: " . number_format($size) . " bytes");
                } catch (S3Exception $e) {
                    $this->warn("  (headObject failed: {$e->getMessage()})");
                }
                return Command::SUCCESS;
            }

            $this->error('✗ Object does NOT exist at this path.');
            $this->line('  The file may have been deleted, or storage_root_path is incorrect.');
            $this->line('  For temp uploads, check if temp cleanup ran before promotion.');
            return Command::FAILURE;
        } catch (S3Exception $e) {
            $this->error('✗ S3 error: ' . $e->getMessage());
            $this->line('  AWS Error Code: ' . ($e->getAwsErrorCode() ?? '—'));
            $this->line('  This is likely the same error causing thumbnail generation to fail.');
            $this->newLine();
            $this->line('Common causes:');
            $this->line('  - NoSuchKey: File was never uploaded or was deleted');
            $this->line('  - AccessDenied: IAM/credentials lack s3:GetObject for this bucket');
            $this->line('  - NoSuchBucket: Bucket does not exist');
            return Command::FAILURE;
        }
    }

    protected function getS3Client(): S3Client
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
