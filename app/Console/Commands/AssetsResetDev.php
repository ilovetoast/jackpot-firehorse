<?php

namespace App\Console\Commands;

use App\Models\ActivityEvent;
use App\Models\Asset;
use App\Models\AssetMetric;
use App\Models\MetricAggregate;
use App\Models\UploadSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Aws\S3\S3Client;
use Aws\Exception\AwsException;

/**
 * Assets Reset Dev Command
 *
 * DEV-ONLY command to completely reset the asset system for debugging.
 * This command:
 * - Deletes all assets, upload sessions, and failed jobs
 * - Removes asset-related activity events
 * - Deletes all asset metrics and metric aggregates (analytics)
 * - Deletes all S3 objects under temp/, assets/, and thumbnails/
 *
 * WARNING: This is a destructive operation that cannot be undone!
 * Only runs in 'local' or 'testing' environments.
 */
class AssetsResetDev extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'assets:reset-dev';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'DEV-ONLY: Completely reset the asset system (destructive, cannot be undone)';

    /**
     * S3 client instance.
     */
    protected ?S3Client $s3Client = null;

    /**
     * Statistics for final summary.
     */
    protected array $stats = [
        'assets_deleted' => 0,
        'upload_sessions_deleted' => 0,
        'failed_jobs_deleted' => 0,
        'activity_events_deleted' => 0,
        'asset_metrics_deleted' => 0,
        'metric_aggregates_deleted' => 0,
        's3_objects_deleted' => 0,
        's3_prefixes' => [],
        'errors' => [],
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Environment check - MUST be local or testing
        $environment = app()->environment();
        if (!in_array($environment, ['local', 'testing'])) {
            $this->error("❌ This command can only run in 'local' or 'testing' environments.");
            $this->error("   Current environment: {$environment}");
            $this->warn("   This is a destructive operation that deletes ALL assets!");
            return Command::FAILURE;
        }

        // Confirmation prompt
        $this->warn("⚠️  WARNING: This will DELETE ALL assets, upload sessions, analytics, and S3 objects!");
        $this->warn("   This operation cannot be undone!");
        if (!$this->confirm('Are you sure you want to continue?', false)) {
            $this->info('Operation cancelled.');
            return Command::SUCCESS;
        }

        $this->info("🚀 Starting asset system reset...");
        $this->newLine();

        try {
            // Step 1: Database cleanup
            $this->cleanupDatabase();

            // Step 2: S3 cleanup
            $this->cleanupS3();

            // Step 3: Summary
            $this->displaySummary();

            $this->newLine();
            $this->info("✅ Asset system reset complete!");
            $this->warn("⚠️  Remember to restart queue workers to clear any running jobs.");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("❌ Fatal error during reset: {$e->getMessage()}");
            Log::error('Assets reset dev command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return Command::FAILURE;
        }
    }

    /**
     * Clean up database tables.
     */
    protected function cleanupDatabase(): void
    {
        $this->info("📊 Cleaning up database...");

        // Delete assets (handle soft deletes)
        try {
            $this->line("  → Deleting assets...");
            
            // Force delete all assets (including soft-deleted)
            // Note: Assets use UUIDs, so no auto-increment to reset
            $count = Asset::withTrashed()->forceDelete();
            $this->stats['assets_deleted'] = $count;
            $this->info("    ✓ Deleted {$count} assets");
        } catch (\Exception $e) {
            $error = "Failed to delete assets: {$e->getMessage()}";
            $this->error("    ✗ {$error}");
            $this->stats['errors'][] = $error;
            Log::error('Assets reset: failed to delete assets', ['error' => $e->getMessage()]);
        }

        // Delete upload sessions
        try {
            $this->line("  → Deleting upload sessions...");
            // Note: Upload sessions use UUIDs, so no auto-increment to reset
            $count = UploadSession::query()->delete();
            $this->stats['upload_sessions_deleted'] = $count;
            $this->info("    ✓ Deleted {$count} upload sessions");
        } catch (\Exception $e) {
            $error = "Failed to delete upload sessions: {$e->getMessage()}";
            $this->error("    ✗ {$error}");
            $this->stats['errors'][] = $error;
            Log::error('Assets reset: failed to delete upload sessions', ['error' => $e->getMessage()]);
        }

        // Delete failed jobs
        try {
            $this->line("  → Clearing failed jobs...");
            $count = DB::table('failed_jobs')->count();
            DB::table('failed_jobs')->truncate();
            $this->stats['failed_jobs_deleted'] = $count;
            $this->info("    ✓ Cleared {$count} failed jobs");
        } catch (\Exception $e) {
            $error = "Failed to clear failed jobs: {$e->getMessage()}";
            $this->error("    ✗ {$error}");
            $this->stats['errors'][] = $error;
            Log::error('Assets reset: failed to clear failed jobs', ['error' => $e->getMessage()]);
        }

        // Delete asset-related activity events
        try {
            $this->line("  → Deleting asset-related activity events...");
            
            $count = ActivityEvent::where(function ($query) {
                $query->where('subject_type', 'App\Models\Asset')
                    ->orWhere('event_type', 'like', 'asset.%')
                    ->orWhere('event_type', 'like', 'ai.system_insight');
            })->delete();
            
            $this->stats['activity_events_deleted'] = $count;
            $this->info("    ✓ Deleted {$count} activity events");
        } catch (\Exception $e) {
            $error = "Failed to delete activity events: {$e->getMessage()}";
            $this->error("    ✗ {$error}");
            $this->stats['errors'][] = $error;
            Log::error('Assets reset: failed to delete activity events', ['error' => $e->getMessage()]);
        }

        // Delete asset metrics (individual metric records)
        try {
            $this->line("  → Deleting asset metrics...");
            
            // Delete all asset metrics (they reference assets via foreign key)
            $count = AssetMetric::query()->delete();
            
            $this->stats['asset_metrics_deleted'] = $count;
            $this->info("    ✓ Deleted {$count} asset metrics");
        } catch (\Exception $e) {
            $error = "Failed to delete asset metrics: {$e->getMessage()}";
            $this->error("    ✗ {$error}");
            $this->stats['errors'][] = $error;
            Log::error('Assets reset: failed to delete asset metrics', ['error' => $e->getMessage()]);
        }

        // Delete metric aggregates (aggregated metric records)
        try {
            $this->line("  → Deleting metric aggregates...");
            
            // Delete all metric aggregates (they reference assets via foreign key)
            $count = MetricAggregate::query()->delete();
            
            $this->stats['metric_aggregates_deleted'] = $count;
            $this->info("    ✓ Deleted {$count} metric aggregates");
        } catch (\Exception $e) {
            $error = "Failed to delete metric aggregates: {$e->getMessage()}";
            $this->error("    ✗ {$error}");
            $this->stats['errors'][] = $error;
            Log::error('Assets reset: failed to delete metric aggregates', ['error' => $e->getMessage()]);
        }

        $this->newLine();
    }

    /**
     * Clean up S3 objects.
     */
    protected function cleanupS3(): void
    {
        $this->info("☁️  Cleaning up S3 objects...");

        try {
            $s3Client = $this->getS3Client();
            $bucket = config('filesystems.disks.s3.bucket');

            if (!$bucket) {
                $this->warn("  ⚠ S3 bucket not configured, skipping S3 cleanup");
                return;
            }

            $this->line("  → Using bucket: {$bucket}");

            // Prefixes to clean up
            $prefixes = ['temp/', 'assets/', 'thumbnails/'];

            foreach ($prefixes as $prefix) {
                try {
                    $this->line("  → Deleting objects under '{$prefix}'...");
                    $deleted = $this->deleteS3Prefix($s3Client, $bucket, $prefix);
                    $this->stats['s3_objects_deleted'] += $deleted;
                    $this->stats['s3_prefixes'][$prefix] = $deleted;
                    $this->info("    ✓ Deleted {$deleted} objects under '{$prefix}'");
                } catch (\Exception $e) {
                    $error = "Failed to delete S3 prefix '{$prefix}': {$e->getMessage()}";
                    $this->error("    ✗ {$error}");
                    $this->stats['errors'][] = $error;
                    Log::error('Assets reset: failed to delete S3 prefix', [
                        'prefix' => $prefix,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->newLine();
        } catch (\Exception $e) {
            $error = "Failed to initialize S3 cleanup: {$e->getMessage()}";
            $this->error("  ✗ {$error}");
            $this->stats['errors'][] = $error;
            Log::error('Assets reset: failed to initialize S3 cleanup', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Delete all objects under a specific S3 prefix.
     *
     * @param S3Client $s3Client
     * @param string $bucket
     * @param string $prefix
     * @return int Number of objects deleted
     */
    protected function deleteS3Prefix(S3Client $s3Client, string $bucket, string $prefix): int
    {
        $deletedCount = 0;
        $continuationToken = null;

        do {
            // List objects with the prefix
            $listParams = [
                'Bucket' => $bucket,
                'Prefix' => $prefix,
                'MaxKeys' => 1000,
            ];

            if ($continuationToken) {
                $listParams['ContinuationToken'] = $continuationToken;
            }

            $result = $s3Client->listObjectsV2($listParams);

            $objects = $result->get('Contents') ?? [];
            
            if (empty($objects)) {
                break;
            }

            // Prepare batch delete
            $deleteParams = [
                'Bucket' => $bucket,
                'Delete' => [
                    'Objects' => array_map(function ($object) {
                        return ['Key' => $object['Key']];
                    }, $objects),
                ],
            ];

            // Delete batch
            $deleteResult = $s3Client->deleteObjects($deleteParams);
            
            $deletedCount += count($deleteResult->get('Deleted') ?? []);
            
            // Check for errors
            $errors = $deleteResult->get('Errors') ?? [];
            if (!empty($errors)) {
                foreach ($errors as $error) {
                    Log::warning('S3 delete error', [
                        'key' => $error['Key'] ?? 'unknown',
                        'code' => $error['Code'] ?? 'unknown',
                        'message' => $error['Message'] ?? 'unknown',
                    ]);
                }
            }

            // Check if there are more objects
            $continuationToken = $result->get('NextContinuationToken');
        } while ($continuationToken);

        return $deletedCount;
    }

    /**
     * Get or create S3 client instance.
     *
     * @return S3Client
     */
    protected function getS3Client(): S3Client
    {
        if ($this->s3Client) {
            return $this->s3Client;
        }

        if (!class_exists(S3Client::class)) {
            throw new \RuntimeException('AWS SDK not installed. Install aws/aws-sdk-php.');
        }

        $config = [
            'version' => 'latest',
            'region' => config('storage.default_region', config('filesystems.disks.s3.region', 'us-east-1')),
        ];

        // Add credentials if provided
        if (config('filesystems.disks.s3.key') && config('filesystems.disks.s3.secret')) {
            $config['credentials'] = [
                'key' => config('filesystems.disks.s3.key'),
                'secret' => config('filesystems.disks.s3.secret'),
            ];
        }

        // Add endpoint for MinIO/local S3
        if (config('filesystems.disks.s3.endpoint')) {
            $config['endpoint'] = config('filesystems.disks.s3.endpoint');
            $config['use_path_style_endpoint'] = config('filesystems.disks.s3.use_path_style_endpoint', false);
        }

        $this->s3Client = new S3Client($config);
        return $this->s3Client;
    }

    /**
     * Display summary of operations.
     */
    protected function displaySummary(): void
    {
        $this->newLine();
        $this->info("📋 Reset Summary:");
        $this->newLine();

        $this->table(
            ['Category', 'Count'],
            [
                ['Assets Deleted', $this->stats['assets_deleted']],
                ['Upload Sessions Deleted', $this->stats['upload_sessions_deleted']],
                ['Failed Jobs Cleared', $this->stats['failed_jobs_deleted']],
                ['Activity Events Deleted', $this->stats['activity_events_deleted']],
                ['Asset Metrics Deleted', $this->stats['asset_metrics_deleted']],
                ['Metric Aggregates Deleted', $this->stats['metric_aggregates_deleted']],
                ['S3 Objects Deleted', $this->stats['s3_objects_deleted']],
            ]
        );

        if (!empty($this->stats['s3_prefixes'])) {
            $this->newLine();
            $this->info("S3 Objects by Prefix:");
            foreach ($this->stats['s3_prefixes'] as $prefix => $count) {
                $this->line("  • {$prefix}: {$count} objects");
            }
        }

        if (!empty($this->stats['errors'])) {
            $this->newLine();
            $this->error("⚠️  Errors encountered:");
            foreach ($this->stats['errors'] as $error) {
                $this->error("  • {$error}");
            }
        }
    }
}
