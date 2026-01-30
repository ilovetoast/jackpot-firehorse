<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Services\Automation\ColorAnalysisService;
use Illuminate\Console\Command;

/**
 * Test AVIF dominant color extraction.
 *
 * Finds an existing AVIF asset (thumbnail_status = COMPLETED, medium thumbnail path set)
 * and runs color analysis against the thumbnail. Use to verify that dominant color
 * is now extracted for AVIF assets (analysis runs on generated JPEG/PNG thumbnail).
 *
 * Usage:
 *   php artisan test:avif-dominant-color           # Use first AVIF asset found
 *   php artisan test:avif-dominant-color {id}      # Use specific asset ID
 */
class TestAvifDominantColorCommand extends Command
{
    protected $signature = 'test:avif-dominant-color {asset_id? : Optional asset ID}';

    protected $description = 'Run dominant color analysis on an existing AVIF asset (uses thumbnail)';

    public function handle(ColorAnalysisService $colorService): int
    {
        $assetId = $this->argument('asset_id');

        $asset = $assetId
            ? Asset::find($assetId)
            : Asset::where('mime_type', 'image/avif')
                ->orWhere('original_filename', 'like', '%.avif')
                ->first();

        if (!$asset) {
            $this->warn('No AVIF asset found. Create an AVIF asset with thumbnail_status=COMPLETED and a medium thumbnail path.');
            return Command::FAILURE;
        }

        if ($asset->mime_type !== 'image/avif' && !str_ends_with(strtolower($asset->original_filename ?? ''), '.avif')) {
            $this->warn("Asset {$asset->id} is not AVIF (mime={$asset->mime_type}, filename=" . ($asset->original_filename ?? '') . ').');
            return Command::FAILURE;
        }

        $this->info("Testing AVIF asset id={$asset->id} filename=" . ($asset->original_filename ?? '') . ' thumbnail_status=' . ($asset->thumbnail_status?->value ?? 'null'));

        $thumbnailPath = $asset->thumbnailPathForStyle('medium');
        if ($thumbnailPath === null || $thumbnailPath === '') {
            $this->warn('Thumbnail path (medium) is missing. Color analysis requires thumbnail_status=COMPLETED and metadata.thumbnails.medium.path.');
            return Command::FAILURE;
        }
        $this->line("Thumbnail path: {$thumbnailPath}");

        $result = $colorService->analyze($asset);

        if ($result === null) {
            $this->warn('Color analysis returned null (skipped or failed). Check logs for [ColorAnalysisService].');
            return Command::FAILURE;
        }

        $clusters = $result['internal']['clusters'] ?? [];
        $buckets = $result['buckets'] ?? [];
        $this->info('Dominant color extracted successfully.');
        $this->line('Clusters: ' . count($clusters) . ' | Buckets: ' . json_encode($buckets));

        return Command::SUCCESS;
    }
}
