<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Enums\ThumbnailStatus;
use Illuminate\Console\Command;

/**
 * Fix Thumbnail Status Command
 * 
 * Fixes assets where thumbnail_status is 'failed' but thumbnails actually exist in metadata.
 * This can happen due to race conditions or errors in the thumbnail generation pipeline.
 */
class FixThumbnailStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'thumbnails:fix-status 
                            {--asset= : Fix specific asset ID}
                            {--tenant= : Fix assets for specific tenant}
                            {--dry-run : Show what would be fixed without making changes}
                            {--limit=100 : Maximum number of assets to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix thumbnail status for assets where thumbnails exist but status is failed';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $assetId = $this->option('asset');
        $tenantId = $this->option('tenant');
        $dryRun = $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $this->info('Scanning for assets with thumbnail status issues...');

        // Build query
        $query = Asset::where('thumbnail_status', ThumbnailStatus::FAILED)
            ->whereNotNull('metadata');

        if ($assetId) {
            $query->where('id', $assetId);
            $limit = 1; // Only process the specified asset
        }

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        // Get assets with potential issues
        $assets = $query->limit($limit)->get();

        if ($assets->isEmpty()) {
            $this->info('No assets found with thumbnail status issues.');
            return 0;
        }

        $this->info("Found {$assets->count()} assets to examine...");

        $fixedCount = 0;
        $skippedCount = 0;

        foreach ($assets as $asset) {
            $metadata = $asset->metadata ?? [];
            $hasThumbnails = !empty($metadata['thumbnails']) && isset($metadata['thumbnails']['thumb']);
            $hasGeneratedAt = isset($metadata['thumbnails_generated_at']);

            if ($hasThumbnails && $hasGeneratedAt) {
                $thumbnailSizes = array_keys($metadata['thumbnails']);
                
                $this->line("Asset {$asset->id} ({$asset->original_filename}):");
                $this->line("  - Current status: {$asset->thumbnail_status->value}");
                $this->line("  - Thumbnails available: " . implode(', ', $thumbnailSizes));
                $this->line("  - Generated at: {$metadata['thumbnails_generated_at']}");

                if (!$dryRun) {
                    // Fix the status
                    $asset->thumbnail_status = ThumbnailStatus::COMPLETED;
                    $asset->thumbnail_error = null;
                    $asset->save();

                    $this->info("  ✅ Fixed thumbnail status to completed");
                } else {
                    $this->comment("  🔍 Would fix thumbnail status to completed (dry run)");
                }

                $fixedCount++;
            } else {
                $this->line("Asset {$asset->id}: Status is correctly failed (no valid thumbnails found)");
                $skippedCount++;
            }
        }

        $this->newLine();
        
        if ($dryRun) {
            $this->info("Dry run completed:");
            $this->info("  - Would fix: {$fixedCount} assets");
            $this->info("  - Would skip: {$skippedCount} assets");
            $this->comment("Run without --dry-run to apply fixes");
        } else {
            $this->info("Thumbnail status fix completed:");
            $this->info("  - Fixed: {$fixedCount} assets");
            $this->info("  - Skipped: {$skippedCount} assets");
            
            if ($fixedCount > 0) {
                $this->comment("Fixed assets should now display proper thumbnails after browser refresh.");
            }
        }

        return 0;
    }
}