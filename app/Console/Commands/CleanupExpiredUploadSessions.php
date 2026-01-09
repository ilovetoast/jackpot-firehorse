<?php

namespace App\Console\Commands;

use App\Models\StorageBucket;
use App\Services\MultipartCleanupService;
use App\Services\UploadCleanupService;
use Illuminate\Console\Command;

/**
 * Command to cleanup expired and terminal upload sessions.
 *
 * This command should be run periodically (e.g., every 1-6 hours) via Laravel scheduler
 * to clean up expired upload sessions and orphaned multipart uploads.
 *
 * Usage: php artisan uploads:cleanup-expired [--threshold=1] [--multipart-threshold=24]
 */
class CleanupExpiredUploadSessions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'uploads:cleanup-expired 
                            {--threshold=1 : Threshold in hours for expired session cleanup}
                            {--multipart-threshold=24 : Threshold in hours for orphaned multipart cleanup}
                            {--bucket= : Specific bucket to clean (optional, cleans all buckets if not specified)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cleanup expired and terminal upload sessions, and orphaned multipart uploads';

    /**
     * Execute the console command.
     */
    public function handle(
        UploadCleanupService $uploadCleanupService,
        MultipartCleanupService $multipartCleanupService
    ): int {
        $threshold = (int) $this->option('threshold');
        $multipartThreshold = (int) $this->option('multipart-threshold');
        $specificBucket = $this->option('bucket');

        $this->info("Cleaning up expired upload sessions (threshold: {$threshold} hours)...");

        // Step 1: Cleanup expired and terminal upload sessions
        $uploadCleanupResults = $uploadCleanupService->cleanupExpiredAndTerminal($threshold);

        $this->info("Upload session cleanup:");
        $this->info("  Attempted: {$uploadCleanupResults['attempted']}");
        $this->info("  Completed: {$uploadCleanupResults['completed']}");
        $this->info("  Failed: {$uploadCleanupResults['failed']}");

        // Step 2: Cleanup orphaned multipart uploads
        $this->info("\nCleaning up orphaned multipart uploads (threshold: {$multipartThreshold} hours)...");

        $buckets = $specificBucket
            ? StorageBucket::where('name', $specificBucket)->get()
            : StorageBucket::all();

        $totalMultipartResults = [
            'scanned' => 0,
            'orphaned' => 0,
            'aborted' => 0,
            'failed' => 0,
        ];

        foreach ($buckets as $bucket) {
            $this->info("  Scanning bucket: {$bucket->name}");

            $multipartResults = $multipartCleanupService->cleanupOrphanedMultipartUploads(
                $bucket->name,
                $multipartThreshold
            );

            $totalMultipartResults['scanned'] += $multipartResults['scanned'];
            $totalMultipartResults['orphaned'] += $multipartResults['orphaned'];
            $totalMultipartResults['aborted'] += $multipartResults['aborted'];
            $totalMultipartResults['failed'] += $multipartResults['failed'];

            $this->info("    Scanned: {$multipartResults['scanned']}");
            $this->info("    Orphaned: {$multipartResults['orphaned']}");
            $this->info("    Aborted: {$multipartResults['aborted']}");
            $this->info("    Failed: {$multipartResults['failed']}");
        }

        $this->info("\nMultipart upload cleanup summary:");
        $this->info("  Total Scanned: {$totalMultipartResults['scanned']}");
        $this->info("  Total Orphaned: {$totalMultipartResults['orphaned']}");
        $this->info("  Total Aborted: {$totalMultipartResults['aborted']}");
        $this->info("  Total Failed: {$totalMultipartResults['failed']}");

        $this->info("\nCleanup completed successfully!");

        return Command::SUCCESS;
    }
}
