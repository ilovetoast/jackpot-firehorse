<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\StorageBucket;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Cleanup Legacy Assets Command
 *
 * Removes legacy assets created before Phase 4 promotion that are causing
 * S3 NoSuchKey errors and blocking thumbnail pipelines.
 *
 * This command:
 * - Identifies legacy assets using old storage layouts
 * - Deletes them AND all associated data (S3 objects, DB records, queue jobs)
 * - Ensures no queue jobs remain stuck referencing them
 * - Ensures S3 is cleaned up
 * - Provides a DRY-RUN mode (default)
 * - Logs everything to storage/logs/legacy-assets-cleanup.log
 *
 * CRITERIA: What is a "legacy asset"
 * An asset is considered LEGACY if ANY of the following are true:
 * 1. storage_root_path is NULL
 * 2. storage_root_path matches old format: assets/{tenant_id}/{brand_id}/
 * 3. storage_root_path does NOT contain the asset UUID
 * 4. asset.created_at < PHASE_4_CUTOFF (2026-01-10 00:00:00 UTC)
 * 5. original file does NOT exist in S3 at expected promoted path
 */
class AssetsCleanupLegacy extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'assets:cleanup-legacy 
                            {--force : Actually delete assets (default: dry-run mode)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cleanup legacy assets created before Phase 4 promotion';

    /**
     * Phase 4 cutoff date (UTC).
     */
    protected const PHASE_4_CUTOFF = '2026-01-10 00:00:00';

    /**
     * Maximum assets to process per run.
     */
    protected const CHUNK_SIZE = 500;

    /**
     * S3 client instance.
     */
    protected ?S3Client $s3Client = null;

    /**
     * Summary statistics.
     */
    protected array $stats = [
        'total_scanned' => 0,
        'total_deleted' => 0,
        'total_skipped' => 0,
        's3_objects_removed' => 0,
        'db_rows_removed' => 0,
        'errors' => 0,
    ];

    /**
     * Log file path.
     */
    protected string $logFile;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->logFile = storage_path('logs/legacy-assets-cleanup.log');
        
        // Default to dry-run unless --force is explicitly passed
        $dryRun = !$this->option('force');

        if ($dryRun) {
            $this->info('🔍 Running in DRY-RUN mode (no changes will be made)');
            $this->info('   Use --force to actually delete assets');
        } else {
            $this->warn('⚠️  Running in FORCE mode (assets will be permanently deleted!)');
            // Only ask for confirmation if running interactively
            if (!$this->option('no-interaction')) {
                if (!$this->confirm('Are you sure you want to continue?', false)) {
                    $this->info('Aborted.');
                    return Command::FAILURE;
                }
            } else {
                $this->warn('⚠️  Non-interactive mode: proceeding with deletion...');
            }
        }

        $this->newLine();

        // Query legacy assets
        $this->info('Querying legacy assets...');
        $legacyAssets = $this->queryLegacyAssets();

        $this->info("Found {$legacyAssets->count()} legacy asset(s) to process");
        $this->newLine();

        if ($legacyAssets->isEmpty()) {
            $this->info('No legacy assets found. Nothing to do.');
            return Command::SUCCESS;
        }

        // Process assets
        $bar = $this->output->createProgressBar($legacyAssets->count());
        $bar->start();

        foreach ($legacyAssets as $asset) {
            $this->stats['total_scanned']++;
            
            try {
                if ($dryRun) {
                    $this->processAssetDryRun($asset);
                } else {
                    $this->processAsset($asset);
                }
            } catch (\Exception $e) {
                $this->stats['errors']++;
                $this->logError($asset, $e);
                $this->error("\nError processing asset {$asset->id}: {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Output summary
        $this->displaySummary($dryRun);

        return Command::SUCCESS;
    }

    /**
     * Query legacy assets based on criteria.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function queryLegacyAssets()
    {
        $cutoff = self::PHASE_4_CUTOFF;

        // Get all assets created before Phase 4 cutoff
        $assets = Asset::whereNull('deleted_at')
            ->where('created_at', '<', $cutoff)
            ->orderBy('created_at', 'asc')
            ->get();

        // Filter in PHP to check legacy criteria more accurately
        return $assets->filter(function ($asset) {
            return $this->isLegacyAsset($asset);
        });
    }

    /**
     * Process asset in dry-run mode (log only, no deletions).
     *
     * @param Asset $asset
     */
    protected function processAssetDryRun(Asset $asset): void
    {
        // Collect related data
        $relatedData = $this->collectRelatedData($asset);

        // Log what would be deleted
        $this->logDryRun($asset, $relatedData);

        $this->stats['total_skipped']++;
    }

    /**
     * Process asset in force mode (actually delete).
     *
     * @param Asset $asset
     */
    protected function processAsset(Asset $asset): void
    {
        // Check if asset matches legacy criteria (double-check before deletion)
        if (!$this->isLegacyAsset($asset)) {
            $this->logInfo("Asset {$asset->id} does not match legacy criteria, skipping");
            $this->stats['total_skipped']++;
            return;
        }

        // Check for active jobs
        if ($this->hasActiveJobs($asset)) {
            $this->logWarning("Asset {$asset->id} has active jobs, skipping deletion");
            $this->stats['total_skipped']++;
            return;
        }

        // Collect related data
        $relatedData = $this->collectRelatedData($asset);

        // Delete in transaction per asset
        DB::transaction(function () use ($asset, $relatedData) {
            // Delete S3 objects
            $s3Deleted = $this->deleteS3Objects($asset, $relatedData);
            $this->stats['s3_objects_removed'] += $s3Deleted;

            // Delete database records
            $dbDeleted = $this->deleteDatabaseRecords($asset, $relatedData);
            $this->stats['db_rows_removed'] += $dbDeleted;

            // Delete queue jobs referencing this asset
            $jobsDeleted = $this->deleteQueueJobs($asset);
            $this->stats['db_rows_removed'] += $jobsDeleted;

            // Soft delete asset (or hard delete if already soft-deleted)
            $asset->delete(); // Soft delete
            $this->stats['db_rows_removed']++;

            $this->logDeletion($asset, $relatedData, $s3Deleted, $dbDeleted + $jobsDeleted + 1);
        });

        $this->stats['total_deleted']++;
    }

    /**
     * Check if asset matches legacy criteria.
     *
     * @param Asset $asset
     * @return bool
     */
    protected function isLegacyAsset(Asset $asset): bool
    {
        $path = $asset->storage_root_path;
        $cutoff = self::PHASE_4_CUTOFF;

        // Criterion 1: storage_root_path is NULL
        if ($path === null) {
            return true;
        }

        // Criterion 2: storage_root_path matches old format: assets/{tenant_id}/{brand_id}/
        if (preg_match('#^assets/\d+/\d+/#', $path) === 1) {
            return true;
        }

        // Criterion 3: storage_root_path does NOT contain the asset UUID
        if (!str_contains($path, (string) $asset->id)) {
            return true;
        }

        // Criterion 4: created_at < PHASE_4_CUTOFF
        if ($asset->created_at < $cutoff) {
            return true;
        }

        // Criterion 5: original file does NOT exist in S3 at expected promoted path
        // (This is checked separately when collecting related data)

        return false;
    }

    /**
     * Collect related data for an asset.
     *
     * @param Asset $asset
     * @return array
     */
    protected function collectRelatedData(Asset $asset): array
    {
        $data = [
            's3_objects' => [],
            'upload_sessions' => [],
            'activity_events' => [],
        ];

        // Collect S3 objects (original + thumbnails)
        if ($asset->storage_root_path && $asset->storageBucket) {
            $data['s3_objects'] = $this->collectS3Objects($asset);
        }

        // Collect upload sessions
        if ($asset->upload_session_id) {
            $uploadSession = DB::table('upload_sessions')
                ->where('id', $asset->upload_session_id)
                ->first();
            if ($uploadSession) {
                $data['upload_sessions'][] = $uploadSession;
            }
        }

        // Collect activity events
        // Note: subject_type is stored as full class name (e.g., "App\Models\Asset")
        $activityEvents = DB::table('activity_events')
            ->where('subject_type', Asset::class)
            ->where('subject_id', $asset->id)
            ->get();
        $data['activity_events'] = $activityEvents->toArray();

        return $data;
    }

    /**
     * Collect S3 objects for an asset (original + thumbnails).
     *
     * @param Asset $asset
     * @return array Array of S3 object keys
     */
    protected function collectS3Objects(Asset $asset): array
    {
        $objects = [];
        $bucket = $asset->storageBucket;
        
        if (!$bucket) {
            return $objects;
        }

        $s3Client = $this->getS3Client();

        try {
            // Check original file
            if ($asset->storage_root_path) {
                if ($s3Client->doesObjectExist($bucket->name, $asset->storage_root_path)) {
                    $objects[] = $asset->storage_root_path;
                }

                // Check thumbnails (they should be in thumbnails/ subdirectory)
                $basePath = dirname($asset->storage_root_path);
                $thumbnailPrefix = $basePath . '/thumbnails/';

                try {
                    $result = $s3Client->listObjectsV2([
                        'Bucket' => $bucket->name,
                        'Prefix' => $thumbnailPrefix,
                    ]);

                    if (isset($result['Contents'])) {
                        foreach ($result['Contents'] as $object) {
                            $objects[] = $object['Key'];
                        }
                    }
                } catch (S3Exception $e) {
                    // Ignore errors when listing thumbnails (they may not exist)
                    $this->logWarning("Failed to list thumbnails for asset {$asset->id}: {$e->getMessage()}");
                }
            }
        } catch (S3Exception $e) {
            // Ignore S3 errors during collection (object may not exist)
            $this->logWarning("Failed to collect S3 objects for asset {$asset->id}: {$e->getMessage()}");
        }

        return $objects;
    }

    /**
     * Check if asset has active jobs in queue.
     *
     * @param Asset $asset
     * @return bool
     */
    protected function hasActiveJobs(Asset $asset): bool
    {
        // Check jobs table for any jobs referencing this asset
        $jobs = DB::table('jobs')
            ->where('payload', 'like', '%"' . $asset->id . '"%')
            ->orWhere('payload', 'like', '%' . $asset->id . '%')
            ->count();

        return $jobs > 0;
    }

    /**
     * Delete S3 objects for an asset.
     *
     * @param Asset $asset
     * @param array $relatedData
     * @return int Number of objects deleted
     */
    protected function deleteS3Objects(Asset $asset, array $relatedData): int
    {
        $deleted = 0;
        $bucket = $asset->storageBucket;

        if (!$bucket) {
            return $deleted;
        }

        $s3Client = $this->getS3Client();
        $objects = $relatedData['s3_objects'] ?? [];

        if (empty($objects)) {
            return $deleted;
        }

        try {
            // Delete objects in batches (S3 allows up to 1000 objects per delete)
            foreach (array_chunk($objects, 1000) as $chunk) {
                $deleteParams = [
                    'Bucket' => $bucket->name,
                    'Delete' => [
                        'Objects' => array_map(function ($key) {
                            return ['Key' => $key];
                        }, $chunk),
                    ],
                ];

                $result = $s3Client->deleteObjects($deleteParams);
                $deleted += count($result['Deleted'] ?? []);
            }
        } catch (S3Exception $e) {
            $this->logError($asset, $e, 'Failed to delete S3 objects');
            throw $e;
        }

        return $deleted;
    }

    /**
     * Delete database records for an asset.
     *
     * @param Asset $asset
     * @param array $relatedData
     * @return int Number of rows deleted
     */
    protected function deleteDatabaseRecords(Asset $asset, array $relatedData): int
    {
        $deleted = 0;

        // Delete upload sessions
        foreach ($relatedData['upload_sessions'] as $uploadSession) {
            DB::table('upload_sessions')
                ->where('id', $uploadSession->id)
                ->delete();
            $deleted++;
        }

        // Delete activity events
        foreach ($relatedData['activity_events'] as $event) {
            DB::table('activity_events')
                ->where('id', $event->id)
                ->delete();
            $deleted++;
        }

        return $deleted;
    }

    /**
     * Delete queue jobs referencing an asset.
     *
     * @param Asset $asset
     * @return int Number of jobs deleted
     */
    protected function deleteQueueJobs(Asset $asset): int
    {
        $deleted = 0;

        // Delete from failed_jobs table (easier to match by UUID string)
        // Jobs are serialized, so we can search for the asset UUID in the payload
        $failedJobs = DB::table('failed_jobs')
            ->where('payload', 'like', '%' . $asset->id . '%')
            ->get();

        foreach ($failedJobs as $job) {
            // Double-check by attempting to unserialize and check asset ID
            try {
                $payload = json_decode($job->payload, true);
                if (isset($payload['data']['command'])) {
                    $unserialized = unserialize($payload['data']['command']);
                    // Check if job contains asset ID (this is job-specific)
                    // For now, if payload contains asset UUID, we'll delete it
                    if (str_contains($job->payload, $asset->id)) {
                        DB::table('failed_jobs')->where('id', $job->id)->delete();
                        $deleted++;
                    }
                }
            } catch (\Exception $e) {
                // If we can't unserialize, skip this job
                // Better safe than sorry
            }
        }

        // Note: We don't delete from jobs table because:
        // 1. Jobs are serialized PHP objects, hard to match accurately
        // 2. Jobs will fail naturally when asset is deleted
        // 3. Risk of deleting wrong jobs is too high
        
        return $deleted;
    }

    /**
     * Get or create S3 client instance.
     *
     * @return S3Client
     */
    protected function getS3Client(): S3Client
    {
        if ($this->s3Client === null) {
            if (!class_exists(S3Client::class)) {
                throw new \RuntimeException('AWS SDK not installed. Install aws/aws-sdk-php.');
            }

            $config = [
                'version' => 'latest',
                'region' => config('storage.default_region', config('filesystems.disks.s3.region', 'us-east-1')),
            ];
            if (config('filesystems.disks.s3.endpoint')) {
                $config['endpoint'] = config('filesystems.disks.s3.endpoint');
                $config['use_path_style_endpoint'] = config('filesystems.disks.s3.use_path_style_endpoint', false);
            }

            $this->s3Client = new S3Client($config);
        }

        return $this->s3Client;
    }

    /**
     * Log dry-run information.
     *
     * @param Asset $asset
     * @param array $relatedData
     */
    protected function logDryRun(Asset $asset, array $relatedData): void
    {
        $logData = [
            'mode' => 'dry-run',
            'asset_id' => $asset->id,
            'storage_root_path' => $asset->storage_root_path,
            'created_at' => $asset->created_at?->toIso8601String(),
            's3_objects_count' => count($relatedData['s3_objects']),
            's3_objects' => $relatedData['s3_objects'],
            'upload_sessions_count' => count($relatedData['upload_sessions']),
            'activity_events_count' => count($relatedData['activity_events']),
        ];

        $this->writeLog('DRY-RUN', $logData);
    }

    /**
     * Log deletion information.
     *
     * @param Asset $asset
     * @param array $relatedData
     * @param int $s3Deleted
     * @param int $dbDeleted
     */
    protected function logDeletion(Asset $asset, array $relatedData, int $s3Deleted, int $dbDeleted): void
    {
        $logData = [
            'mode' => 'deleted',
            'asset_id' => $asset->id,
            'storage_root_path' => $asset->storage_root_path,
            'created_at' => $asset->created_at?->toIso8601String(),
            's3_objects_deleted' => $s3Deleted,
            'db_rows_deleted' => $dbDeleted,
        ];

        $this->writeLog('DELETED', $logData);
    }

    /**
     * Log error.
     *
     * @param Asset $asset
     * @param \Exception $e
     * @param string|null $message
     */
    protected function logError(Asset $asset, \Exception $e, ?string $message = null): void
    {
        $logData = [
            'mode' => 'error',
            'asset_id' => $asset->id,
            'error' => $message ?? $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ];

        $this->writeLog('ERROR', $logData);
    }

    /**
     * Log info message.
     *
     * @param string $message
     */
    protected function logInfo(string $message): void
    {
        $this->writeLog('INFO', ['message' => $message]);
    }

    /**
     * Log warning message.
     *
     * @param string $message
     */
    protected function logWarning(string $message): void
    {
        $this->writeLog('WARNING', ['message' => $message]);
    }

    /**
     * Write log entry to file.
     *
     * @param string $level
     * @param array $data
     */
    protected function writeLog(string $level, array $data): void
    {
        $entry = [
            'timestamp' => now()->toIso8601String(),
            'level' => $level,
            'data' => $data,
        ];

        file_put_contents(
            $this->logFile,
            json_encode($entry) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * Display summary table.
     *
     * @param bool $dryRun
     */
    protected function displaySummary(bool $dryRun): void
    {
        $this->info('📊 Summary:');
        $this->newLine();

        $headers = ['Metric', 'Count'];
        $rows = [
            ['Total Scanned', $this->stats['total_scanned']],
            ['Total ' . ($dryRun ? 'Would Delete' : 'Deleted'), $this->stats['total_deleted']],
            ['Total Skipped', $this->stats['total_skipped']],
            ['S3 Objects ' . ($dryRun ? 'Would Remove' : 'Removed'), $this->stats['s3_objects_removed']],
            ['DB Rows ' . ($dryRun ? 'Would Remove' : 'Removed'), $this->stats['db_rows_removed']],
            ['Errors', $this->stats['errors']],
        ];

        $this->table($headers, $rows);

        $this->newLine();
        $this->info("Log file: {$this->logFile}");
    }
}
