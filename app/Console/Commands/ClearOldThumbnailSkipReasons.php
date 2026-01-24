<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Enums\ThumbnailStatus;
use Illuminate\Console\Command;

/**
 * Clear Old Thumbnail Skip Reasons Command
 * 
 * Clears skip reasons for formats that are now supported (e.g., TIFF, AVIF via Imagick).
 * This allows previously skipped assets to be regenerated.
 */
class ClearOldThumbnailSkipReasons extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'thumbnails:clear-skip-reasons 
                            {--format= : Specific format to clear (tiff, avif, or all)}
                            {--dry-run : Show what would be cleared without making changes}
                            {--force : Force regeneration by setting status to PENDING}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear old thumbnail skip reasons for formats that are now supported (TIFF, AVIF)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $format = $this->option('format') ?? 'all';
        $dryRun = $this->option('dry-run');
        $forceRegenerate = $this->option('force');

        // Check if Imagick is available
        if (!extension_loaded('imagick')) {
            $this->warn('Imagick extension is not loaded. TIFF/AVIF support requires Imagick.');
            if (!$this->confirm('Continue anyway? (Skip reasons will be cleared but thumbnails cannot be generated)')) {
                return 0;
            }
        }

        $this->info('Scanning for assets with old skip reasons...');

        // Build query for assets with skip reasons
        $query = Asset::where('thumbnail_status', ThumbnailStatus::SKIPPED)
            ->whereNotNull('metadata');

        $clearedCount = 0;
        $skippedCount = 0;

        $assets = $query->get();

        foreach ($assets as $asset) {
            $metadata = $asset->metadata ?? [];
            $skipReason = $metadata['thumbnail_skip_reason'] ?? null;

            if (!$skipReason) {
                continue;
            }

            $mimeType = strtolower($asset->mime_type ?? '');
            $extension = strtolower(pathinfo($asset->original_filename ?? '', PATHINFO_EXTENSION));

            $shouldClear = false;
            $formatName = '';

            // Check TIFF
            if (($format === 'all' || $format === 'tiff') && 
                $skipReason === 'unsupported_format:tiff' &&
                ($mimeType === 'image/tiff' || $mimeType === 'image/tif' || $extension === 'tiff' || $extension === 'tif')) {
                if (extension_loaded('imagick')) {
                    $shouldClear = true;
                    $formatName = 'TIFF';
                }
            }

            // Check AVIF
            if (($format === 'all' || $format === 'avif') && 
                $skipReason === 'unsupported_format:avif' &&
                ($mimeType === 'image/avif' || $extension === 'avif')) {
                if (extension_loaded('imagick')) {
                    $shouldClear = true;
                    $formatName = 'AVIF';
                }
            }

            if ($shouldClear) {
                $this->line("Asset {$asset->id} ({$asset->original_filename}):");
                $this->line("  - Format: {$formatName}");
                $this->line("  - Current status: {$asset->thumbnail_status->value}");
                $this->line("  - Skip reason: {$skipReason}");

                if (!$dryRun) {
                    // Clear skip reason
                    unset($metadata['thumbnail_skip_reason']);
                    
                    $updateData = [
                        'metadata' => $metadata,
                        'thumbnail_error' => null,
                    ];

                    // Optionally reset status to PENDING to allow regeneration
                    if ($forceRegenerate) {
                        $updateData['thumbnail_status'] = ThumbnailStatus::PENDING;
                        $this->line("  ✅ Cleared skip reason and reset status to PENDING (ready for regeneration)");
                    } else {
                        $this->line("  ✅ Cleared skip reason (status remains SKIPPED - use --force to reset)");
                    }

                    $asset->update($updateData);
                } else {
                    $this->comment("  🔍 Would clear skip reason (dry run)");
                }

                $clearedCount++;
            } else {
                $skippedCount++;
            }
        }

        $this->newLine();
        
        if ($dryRun) {
            $this->info("Dry run completed:");
            $this->info("  - Would clear: {$clearedCount} assets");
            $this->info("  - Would skip: {$skippedCount} assets");
            $this->comment("Run without --dry-run to apply changes");
            if ($clearedCount > 0) {
                $this->comment("Use --force to also reset status to PENDING for automatic regeneration");
            }
        } else {
            $this->info("Skip reason clearing completed:");
            $this->info("  - Cleared: {$clearedCount} assets");
            $this->info("  - Skipped: {$skippedCount} assets");
            
            if ($clearedCount > 0) {
                if ($forceRegenerate) {
                    $this->comment("Assets have been reset to PENDING status and will regenerate on next job run.");
                } else {
                    $this->comment("Skip reasons cleared. Use --force flag to reset status to PENDING for automatic regeneration.");
                    $this->comment("Or manually trigger regeneration from the asset details modal.");
                }
            }
        }

        return 0;
    }
}