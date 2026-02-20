<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Enums\ThumbnailStatus;
use Illuminate\Console\Command;

/**
 * Sync thumbnail metadata from AssetVersion to Asset.
 *
 * When version-aware uploads store thumbnails on the version only, the asset
 * has no thumbnails in metadata. This causes "Visual metadata invalid" and
 * missing thumbnails in the grid. This command copies thumbnail metadata from
 * the current version to the asset.
 *
 * Run: php artisan thumbnails:sync-version-to-asset [--dry-run] [--asset=id]
 */
class SyncVersionThumbnailsToAssetCommand extends Command
{
    protected $signature = 'thumbnails:sync-version-to-asset
                            {--asset= : Sync specific asset ID}
                            {--tenant= : Limit to tenant ID}
                            {--dry-run : Show what would be synced without making changes}
                            {--limit=500 : Maximum number of assets to process}';

    protected $description = 'Sync thumbnail metadata from current version to asset (fixes version-aware uploads)';

    public function handle(): int
    {
        $assetId = $this->option('asset');
        $tenantId = $this->option('tenant');
        $dryRun = $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $query = Asset::with('currentVersion')
            ->whereNotNull('metadata')
            ->where(function ($q) {
                $q->where('thumbnail_status', ThumbnailStatus::COMPLETED)
                    ->orWhere('thumbnail_status', ThumbnailStatus::PROCESSING);
            });

        if ($assetId) {
            $query->where('id', $assetId);
            $limit = 1;
        }

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        $assets = $query->limit($limit)->get();

        if ($assets->isEmpty()) {
            $this->info('No assets found to process.');
            return 0;
        }

        $this->info('Scanning ' . $assets->count() . ' assets for version→asset thumbnail sync...');

        $synced = 0;
        $skipped = 0;

        foreach ($assets as $asset) {
            $version = $asset->currentVersion;
            if (!$version) {
                $skipped++;
                continue;
            }

            $versionMeta = $version->metadata ?? [];
            $hasThumbnails = !empty($versionMeta['thumbnails']) && isset($versionMeta['thumbnails']['thumb']);
            if (!$hasThumbnails) {
                $skipped++;
                continue;
            }

            $assetMeta = $asset->metadata ?? [];
            $assetHasThumbnails = !empty($assetMeta['thumbnails']) && isset($assetMeta['thumbnails']['thumb']);
            if ($assetHasThumbnails) {
                $skipped++;
                continue;
            }

            $thumbnailKeys = [
                'thumbnails', 'preview_thumbnails', 'thumbnail_dimensions',
                'image_width', 'image_height', 'thumbnails_generated', 'thumbnails_generated_at',
                'thumbnail_timeout', 'thumbnail_timeout_reason',
            ];

            $toMerge = [];
            foreach ($thumbnailKeys as $key) {
                if (isset($versionMeta[$key])) {
                    $toMerge[$key] = $versionMeta[$key];
                }
            }

            if (empty($toMerge)) {
                $skipped++;
                continue;
            }

            $this->line("Asset {$asset->id} ({$asset->original_filename}): syncing " . count($toMerge) . " keys from version");

            if (!$dryRun) {
                $asset->update([
                    'metadata' => array_merge($assetMeta, $toMerge),
                ]);
                $synced++;
            } else {
                $this->comment("  🔍 Would sync (dry run)");
                $synced++;
            }
        }

        $this->newLine();
        if ($dryRun) {
            $this->info("Dry run: would sync {$synced} assets, skipped {$skipped}");
            $this->comment('Run without --dry-run to apply');
        } else {
            $this->info("Synced {$synced} assets, skipped {$skipped}");
        }

        return 0;
    }
}
