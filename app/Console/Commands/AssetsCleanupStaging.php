<?php

namespace App\Console\Commands;

use App\Enums\UploadStatus;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Services\TenantBucketService;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Assets Cleanup Staging
 *
 * SAFE, tenant-scoped cleanup for STAGING ONLY.
 * - Resolves bucket via TenantBucketService (never config filesystems.disks.s3.bucket).
 * - Uses EC2 instance role for S3 (no static credentials).
 * - Defaults to --dry-run; requires --force to delete.
 */
class AssetsCleanupStaging extends Command
{
    protected $signature = 'assets:cleanup-staging
        {tenant_id : Tenant ID to clean}
        {--temp-only : Only delete temporary upload objects and sessions}
        {--orphans : Delete orphaned upload sessions and orphaned S3 objects}
        {--dry-run : Show what would be deleted without deleting anything (default)}
        {--force : Actually perform deletions}
    ';

    protected $description = 'Staging-only: Safe tenant-scoped cleanup (temp uploads and/or orphans). Defaults to dry-run.';

    protected const ORPHAN_SESSION_THRESHOLD_HOURS = 24;

    protected const TEMP_PREFIX = 'temp/uploads/';

    protected const S3_LIST_BATCH = 1000;

    protected const S3_DELETE_BATCH = 1000;

    protected ?S3Client $s3Client = null;

    /** @var array{upload_sessions_identified: int, upload_sessions_deleted: int, s3_objects_identified: int, s3_objects_deleted: int, errors: array<string>} */
    protected array $stats = [
        'upload_sessions_identified' => 0,
        'upload_sessions_deleted' => 0,
        's3_objects_identified' => 0,
        's3_objects_deleted' => 0,
        'errors' => [],
    ];

    public function handle(TenantBucketService $bucketService): int
    {
        if (app()->environment() !== 'staging') {
            $this->error('This command may only run in the staging environment.');
            $this->error('Current environment: ' . app()->environment());

            return self::FAILURE;
        }

        $tenantId = (int) $this->argument('tenant_id');
        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            $this->error("Tenant not found: {$tenantId}.");

            return self::FAILURE;
        }

        $bucketName = null;
        try {
            $bucketName = $bucketService->resolveActiveBucketOrFail($tenant)->name;
        } catch (\Throwable $e) {
            $this->error('Could not resolve bucket for tenant: ' . $e->getMessage());
            Log::error('assets:cleanup-staging bucket resolution failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return self::FAILURE;
        }

        $tempOnly = $this->option('temp-only');
        $orphans = $this->option('orphans');
        $dryRun = ! $this->option('force');
        if ($dryRun && $this->option('dry-run') === false) {
            $dryRun = true;
        }

        if (! $tempOnly && ! $orphans) {
            $this->warn('No cleanup mode selected. Use --temp-only and/or --orphans.');
            $this->warn('Exiting. Nothing was deleted.');

            return self::FAILURE;
        }

        if ($this->option('force') && ! $dryRun && ! $this->confirm('This will permanently delete the selected upload sessions and S3 objects. Continue?', false)) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        $this->s3Client = $this->createS3Client();

        if ($tempOnly) {
            $this->runTempOnlyCleanup($tenant, $bucketName, $dryRun);
        }
        if ($orphans) {
            $this->runOrphanCleanup($tenant, $bucketName, $dryRun);
        }

        $this->displaySummary($tenant, $bucketName, $dryRun);

        return empty($this->stats['errors']) ? self::SUCCESS : self::FAILURE;
    }

    protected function runTempOnlyCleanup(Tenant $tenant, string $bucketName, bool $dryRun): void
    {
        $sessions = UploadSession::where('tenant_id', $tenant->id)
            ->whereIn('status', [UploadStatus::INITIATING, UploadStatus::UPLOADING, UploadStatus::FAILED, UploadStatus::CANCELLED])
            ->get();

        $this->stats['upload_sessions_identified'] += $sessions->count();

        $objectCount = 0;
        foreach ($sessions as $session) {
            $prefix = self::TEMP_PREFIX . $session->id . '/';
            $count = $this->countOrDeletePrefix($bucketName, $prefix, $dryRun);
            $objectCount += $count;
            if (! $dryRun) {
                if ($count > 0) {
                    $this->logDeletion($tenant->id, $bucketName, 'temp', $prefix, $count);
                }
                if ($this->deleteUploadSession($session)) {
                    $this->stats['upload_sessions_deleted']++;
                }
            }
        }

        $this->stats['s3_objects_identified'] += $objectCount;
        if (! $dryRun) {
            $this->stats['s3_objects_deleted'] += $objectCount;
        }
    }

    protected function runOrphanCleanup(Tenant $tenant, string $bucketName, bool $dryRun): void
    {
        $threshold = now()->subHours(self::ORPHAN_SESSION_THRESHOLD_HOURS);

        $sessions = UploadSession::where('tenant_id', $tenant->id)
            ->whereIn('status', [UploadStatus::INITIATING, UploadStatus::UPLOADING])
            ->where(function ($q) use ($threshold) {
                $q->where('updated_at', '<', $threshold)
                    ->orWhere('expires_at', '<', now());
            })
            ->get();

        $this->stats['upload_sessions_identified'] += $sessions->count();

        $objectCount = 0;
        foreach ($sessions as $session) {
            $prefix = self::TEMP_PREFIX . $session->id . '/';
            $count = $this->countOrDeletePrefix($bucketName, $prefix, $dryRun);
            $objectCount += $count;
            if (! $dryRun) {
                if ($count > 0) {
                    $this->logDeletion($tenant->id, $bucketName, 'orphan_temp', $prefix, $count);
                }
                if ($this->deleteUploadSession($session)) {
                    $this->stats['upload_sessions_deleted']++;
                }
            }
        }

        $this->stats['s3_objects_identified'] += $objectCount;
        if (! $dryRun) {
            $this->stats['s3_objects_deleted'] += $objectCount;
        }

        $tempOrphanKeys = $this->identifyTempOrphanKeys($tenant, $bucketName);
        $this->stats['s3_objects_identified'] += count($tempOrphanKeys);
        if (! $dryRun && ! empty($tempOrphanKeys)) {
            $deleted = $this->deleteS3Keys($bucketName, $tempOrphanKeys);
            $this->stats['s3_objects_deleted'] += $deleted;
            $this->logDeletion($tenant->id, $bucketName, 'orphan_temp_keys', 'temp/uploads/', $deleted);
        }
    }

    protected function identifyTempOrphanKeys(Tenant $tenant, string $bucketName): array
    {
        $validSessionIds = UploadSession::where('tenant_id', $tenant->id)->pluck('id')->flip()->all();
        $orphanKeys = [];
        $continuationToken = null;

        do {
            $params = [
                'Bucket' => $bucketName,
                'Prefix' => self::TEMP_PREFIX,
                'MaxKeys' => self::S3_LIST_BATCH,
            ];
            if ($continuationToken) {
                $params['ContinuationToken'] = $continuationToken;
            }

            try {
                $result = $this->s3Client->listObjectsV2($params);
            } catch (S3Exception $e) {
                $this->stats['errors'][] = 'List temp prefix: ' . $e->getMessage();
                break;
            }

            $contents = $result->get('Contents') ?? [];
            foreach ($contents as $obj) {
                $key = $obj['Key'] ?? '';
                $parts = explode('/', $key);
                if (count($parts) >= 3) {
                    $sessionId = $parts[2];
                    if (! isset($validSessionIds[$sessionId])) {
                        $orphanKeys[] = $key;
                    }
                }
            }

            $continuationToken = $result->get('NextContinuationToken');
        } while ($continuationToken);

        return $orphanKeys;
    }

    protected function countOrDeletePrefix(string $bucketName, string $prefix, bool $dryRun): int
    {
        $keys = [];
        $continuationToken = null;

        do {
            $params = [
                'Bucket' => $bucketName,
                'Prefix' => $prefix,
                'MaxKeys' => self::S3_LIST_BATCH,
            ];
            if ($continuationToken) {
                $params['ContinuationToken'] = $continuationToken;
            }

            try {
                $result = $this->s3Client->listObjectsV2($params);
            } catch (S3Exception $e) {
                $this->stats['errors'][] = "List {$prefix}: " . $e->getMessage();

                return 0;
            }

            $contents = $result->get('Contents') ?? [];
            foreach ($contents as $obj) {
                if (isset($obj['Key'])) {
                    $keys[] = $obj['Key'];
                }
            }
            $continuationToken = $result->get('NextContinuationToken');
        } while ($continuationToken);

        if ($dryRun || empty($keys)) {
            return count($keys);
        }

        return $this->deleteS3Keys($bucketName, $keys);
    }

    protected function deleteS3Keys(string $bucketName, array $keys): int
    {
        $deleted = 0;
        foreach (array_chunk($keys, self::S3_DELETE_BATCH) as $chunk) {
            $objects = array_map(fn ($key) => ['Key' => $key], $chunk);
            try {
                $result = $this->s3Client->deleteObjects([
                    'Bucket' => $bucketName,
                    'Delete' => ['Objects' => $objects],
                ]);
                $deleted += count($result->get('Deleted') ?? []);
            } catch (S3Exception $e) {
                $this->stats['errors'][] = 'DeleteObjects: ' . $e->getMessage();
            }
        }

        return $deleted;
    }

    protected function deleteUploadSession(UploadSession $session): bool
    {
        try {
            $session->delete();

            return true;
        } catch (\Throwable $e) {
            $this->stats['errors'][] = "Delete upload session {$session->id}: " . $e->getMessage();

            return false;
        }
    }

    protected function logDeletion(int $tenantId, string $bucketName, string $category, string $prefixOrDetail, int $count): void
    {
        Log::info('assets:cleanup-staging deletion', [
            'tenant_id' => $tenantId,
            'bucket' => $bucketName,
            'category' => $category,
            'detail' => $prefixOrDetail,
            'object_count' => $count,
        ]);
    }

    protected function displaySummary(Tenant $tenant, string $bucketName, bool $dryRun): void
    {
        $mode = $dryRun ? 'dry-run' : 'force';
        $this->table(
            ['Tenant', 'Bucket', 'Mode', 'Sessions identified', 'Sessions deleted', 'S3 objects identified', 'S3 objects deleted'],
            [[
                $tenant->name ?? (string) $tenant->id,
                $bucketName,
                $mode,
                $this->stats['upload_sessions_identified'],
                $this->stats['upload_sessions_deleted'],
                $this->stats['s3_objects_identified'],
                $this->stats['s3_objects_deleted'],
            ]]
        );

        if ($dryRun && ($this->stats['upload_sessions_identified'] > 0 || $this->stats['s3_objects_identified'] > 0)) {
            $this->info('Dry run: no changes made. Use --force to perform deletions.');
        }

        if (! empty($this->stats['errors'])) {
            $this->newLine();
            $this->error('Errors:');
            foreach ($this->stats['errors'] as $err) {
                $this->error('  ' . $err);
            }
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
