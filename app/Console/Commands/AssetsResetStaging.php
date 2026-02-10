<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Services\TenantBucketService;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Assets Reset Staging
 *
 * STAGING-ONLY. Uses tenant staging buckets per docs/s3-bucket-strategy.md:
 * - Expected bucket name from TenantBucketService (STORAGE_BUCKET_NAME_PATTERN, e.g. jackpot-staging-{company_slug})
 * - Empties and deletes that bucket in S3 (and any legacy tenant bucket rows)
 * - Deletes StorageBucket row(s) for the tenant so tenants:ensure-buckets can recreate them
 * - Optional --wipe-db: deletes assets and upload sessions for the tenant (for clean seeding)
 *
 * Safe workflow: run this, then run tenants:ensure-buckets to create fresh buckets for seeding.
 * Locked to APP_ENV=staging only.
 */
class AssetsResetStaging extends Command
{
    protected $signature = 'assets:reset-staging
        {--tenant-id= : Limit to a single tenant by ID}
        {--wipe-db : Also delete assets and upload sessions for the tenant(s) (for clean seeding)}
        {--dry-run : List what would be done without deleting anything}
        {--force : Actually perform deletions (required for non-dry-run)}
    ';

    protected $description = 'Staging-only: Empty tenant buckets, delete buckets and DB rows so tenants:ensure-buckets can recreate.';

    protected const S3_LIST_BATCH = 1000;

    protected const S3_DELETE_BATCH = 1000;

    protected ?S3Client $s3Client = null;

    /** @var array<int, array{bucket: string, objects_deleted: int, bucket_deleted: bool, rows_deleted: int, db_wipe: int}> */
    protected array $perTenantSummary = [];

    public function handle(TenantBucketService $bucketService): int
    {
        if (app()->environment() !== 'staging') {
            $this->error('This command may only run in the staging environment.');
            $this->error('Current environment: ' . app()->environment());
            return self::FAILURE;
        }

        $tenantId = $this->option('tenant-id');
        $tenants = $tenantId
            ? Tenant::where('id', (int) $tenantId)->get()
            : Tenant::orderBy('id')->get();

        if ($tenants->isEmpty()) {
            $this->warn('No tenants found.');
            return self::SUCCESS;
        }

        $dryRun = $this->option('dry-run');
        $force = $this->option('force');
        $wipeDb = $this->option('wipe-db');

        if (! $dryRun && ! $force) {
            $this->error('Use --force to perform deletions, or --dry-run to see what would be done.');
            return self::FAILURE;
        }

        if (! $dryRun && ! $this->confirm('This will empty and delete all tenant buckets and remove StorageBucket rows. Continue?', false)) {
            $this->info('Aborted.');
            return self::SUCCESS;
        }

        $this->s3Client = $this->createS3Client();

        foreach ($tenants as $tenant) {
            $this->processTenant($bucketService, $tenant, $dryRun, $wipeDb);
        }

        $this->displaySummary($dryRun);
        return self::SUCCESS;
    }

    protected function processTenant(TenantBucketService $bucketService, Tenant $tenant, bool $dryRun, bool $wipeDb): void
    {
        $this->perTenantSummary[$tenant->id] = [
            'tenant_name' => $tenant->name ?? (string) $tenant->id,
            'buckets' => [],
            'db_wipe' => 0,
        ];

        // Use tenant staging bucket from config (docs/s3-bucket-strategy.md): STORAGE_BUCKET_NAME_PATTERN → e.g. jackpot-staging-{company_slug}
        $expectedBucketName = $bucketService->getBucketName($tenant);
        $dbBuckets = StorageBucket::where('tenant_id', $tenant->id)->get();
        $bucketNamesToProcess = collect([$expectedBucketName])
            ->merge($dbBuckets->pluck('name'))
            ->unique()
            ->values()
            ->all();

        foreach ($bucketNamesToProcess as $bucketName) {
            $bucketRow = $dbBuckets->firstWhere('name', $bucketName);
            $objectsDeleted = 0;
            $bucketDeleted = false;

            if (! $this->bucketExistsInS3($bucketName)) {
                $this->line("  Tenant {$tenant->id} ({$tenant->name}): bucket {$bucketName} does not exist in S3, skipping S3 steps.");
                if (! $dryRun && $bucketRow) {
                    $bucketRow->forceDelete();
                }
                $this->perTenantSummary[$tenant->id]['buckets'][] = [
                    'bucket' => $bucketName,
                    'objects_deleted' => 0,
                    'bucket_deleted' => false,
                    'row_deleted' => $bucketRow ? true : false,
                ];
                continue;
            }

            if ($dryRun) {
                $count = $this->countObjectsInBucket($bucketName);
                $this->line("  [dry-run] Tenant {$tenant->id} ({$tenant->name}): bucket {$bucketName} would empty ({$count} objects), then bucket deleted, row removed.");
                $this->perTenantSummary[$tenant->id]['buckets'][] = [
                    'bucket' => $bucketName,
                    'objects_deleted' => $count,
                    'bucket_deleted' => true,
                    'row_deleted' => true,
                ];
                continue;
            }

            $objectsDeleted = $this->emptyBucket($bucketName);
            $this->line("  Tenant {$tenant->id}: emptied {$bucketName} ({$objectsDeleted} objects).");

            try {
                $this->s3Client->deleteBucket(['Bucket' => $bucketName]);
                $bucketDeleted = true;
                $this->line("  Tenant {$tenant->id}: deleted bucket {$bucketName}.");
            } catch (S3Exception $e) {
                $this->error("  Failed to delete bucket {$bucketName}: " . $e->getMessage());
                Log::error('assets:reset-staging deleteBucket failed', [
                    'tenant_id' => $tenant->id,
                    'bucket' => $bucketName,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($bucketRow) {
                $bucketRow->forceDelete();
            }
            $this->perTenantSummary[$tenant->id]['buckets'][] = [
                'bucket' => $bucketName,
                'objects_deleted' => $objectsDeleted,
                'bucket_deleted' => $bucketDeleted,
                'row_deleted' => (bool) $bucketRow,
            ];
        }

        // Remove any remaining StorageBucket rows for this tenant (e.g. legacy names we didn't find in S3)
        if (! $dryRun) {
            $remaining = StorageBucket::where('tenant_id', $tenant->id)->count();
            if ($remaining > 0) {
                StorageBucket::where('tenant_id', $tenant->id)->forceDelete();
                $this->line("  Tenant {$tenant->id}: removed {$remaining} remaining storage bucket row(s).");
            }
        }

        if ($wipeDb && ! $dryRun) {
            $deleted = $this->wipeTenantAssetData($tenant);
            $this->perTenantSummary[$tenant->id]['db_wipe'] = $deleted;
            $this->line("  Tenant {$tenant->id}: wiped {$deleted} asset-related DB records.");
        } elseif ($wipeDb && $dryRun) {
            $wouldDelete = $this->countTenantAssetData($tenant);
            $this->perTenantSummary[$tenant->id]['db_wipe'] = $wouldDelete;
            $this->line("  [dry-run] Tenant {$tenant->id}: would wipe {$wouldDelete} asset-related DB records.");
        }
    }

    protected function bucketExistsInS3(string $bucketName): bool
    {
        try {
            $this->s3Client->headBucket(['Bucket' => $bucketName]);
            return true;
        } catch (S3Exception $e) {
            if ($e->getAwsErrorCode() === '404' || $e->getStatusCode() === 404) {
                return false;
            }
            throw $e;
        }
    }

    protected function countObjectsInBucket(string $bucketName): int
    {
        $count = 0;
        $continuationToken = null;
        do {
            $params = [
                'Bucket' => $bucketName,
                'MaxKeys' => self::S3_LIST_BATCH,
            ];
            if ($continuationToken) {
                $params['ContinuationToken'] = $continuationToken;
            }
            $result = $this->s3Client->listObjectsV2($params);
            $contents = $result->get('Contents') ?? [];
            $count += count($contents);
            $continuationToken = $result->get('NextContinuationToken');
        } while ($continuationToken);
        return $count;
    }

    /**
     * Empty the bucket (delete all objects). Returns number of objects deleted.
     */
    protected function emptyBucket(string $bucketName): int
    {
        $deleted = 0;
        $continuationToken = null;

        do {
            $params = [
                'Bucket' => $bucketName,
                'MaxKeys' => self::S3_LIST_BATCH,
            ];
            if ($continuationToken) {
                $params['ContinuationToken'] = $continuationToken;
            }
            $result = $this->s3Client->listObjectsV2($params);
            $contents = $result->get('Contents') ?? [];
            if (empty($contents)) {
                break;
            }
            $objects = array_map(fn ($o) => ['Key' => $o['Key']], $contents);
            $deleteResult = $this->s3Client->deleteObjects([
                'Bucket' => $bucketName,
                'Delete' => ['Objects' => $objects],
            ]);
            $deleted += count($deleteResult->get('Deleted') ?? []);
            $continuationToken = $result->get('NextContinuationToken');
        } while ($continuationToken);

        return $deleted;
    }

    protected function wipeTenantAssetData(Tenant $tenant): int
    {
        $assetsCount = Asset::where('tenant_id', $tenant->id)->count();
        $sessionsCount = UploadSession::where('tenant_id', $tenant->id)->count();

        DB::transaction(function () use ($tenant) {
            Asset::where('tenant_id', $tenant->id)->forceDelete();
            UploadSession::where('tenant_id', $tenant->id)->delete();
        });

        return $assetsCount + $sessionsCount;
    }

    protected function countTenantAssetData(Tenant $tenant): int
    {
        return Asset::where('tenant_id', $tenant->id)->count()
            + UploadSession::where('tenant_id', $tenant->id)->count();
    }

    protected function displaySummary(bool $dryRun): void
    {
        $this->newLine();
        $this->info($dryRun ? 'Dry run summary (no changes made):' : 'Summary:');
        $this->newLine();

        foreach ($this->perTenantSummary as $tid => $summary) {
            $this->line("Tenant {$tid} ({$summary['tenant_name']}):");
            foreach ($summary['buckets'] as $b) {
                $this->line("  Bucket {$b['bucket']}: {$b['objects_deleted']} objects deleted, bucket deleted: " . ($b['bucket_deleted'] ? 'yes' : 'no') . ", row removed: " . ($b['row_deleted'] ? 'yes' : 'no'));
            }
            if (($summary['db_wipe'] ?? 0) > 0) {
                $this->line("  DB wipe: {$summary['db_wipe']} records.");
            }
        }

        if ($dryRun) {
            $this->newLine();
            $this->info('Run with --force to perform deletions. Then run: php artisan tenants:ensure-buckets');
        } else {
            $this->newLine();
            $this->info('Next: run php artisan tenants:ensure-buckets to create buckets for seeding.');
        }
    }

    protected function createS3Client(): S3Client
    {
        if (! class_exists(S3Client::class)) {
            throw new RuntimeException('AWS SDK required. composer require aws/aws-sdk-php');
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
