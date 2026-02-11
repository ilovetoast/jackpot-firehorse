<?php

namespace App\Console\Commands;

use App\Models\Asset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time backfill: sync approved starred and quality_rating from asset_metadata
 * into assets.metadata so grid sort by Starred/Quality sees them.
 *
 * Run once after deploying the sync-on-write fix, e.g.:
 *   sail artisan metadata:sync-sort-to-assets
 *   sail artisan metadata:sync-sort-to-assets --dry-run
 */
class SyncSortMetadataToAssets extends Command
{
    protected $signature = 'metadata:sync-sort-to-assets
                            {--dry-run : Show what would be updated without writing}
                            {--limit= : Max assets to process (default no limit)}';

    protected $description = 'Backfill assets.metadata with approved starred/quality_rating from asset_metadata for grid sort';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        $this->info('Finding latest approved starred/quality_rating per asset from asset_metadata...');

        // Latest approved row id per asset per field
        $latestRows = DB::table('asset_metadata')
            ->whereNotNull('approved_at')
            ->select('asset_id', 'metadata_field_id', DB::raw('MAX(id) as id'))
            ->groupBy('asset_id', 'metadata_field_id')
            ->get();

        $ids = $latestRows->pluck('id')->toArray();
        if (empty($ids)) {
            $this->info('No approved asset_metadata rows found.');
            return 0;
        }

        $rows = DB::table('asset_metadata')
            ->join('metadata_fields', 'metadata_fields.id', '=', 'asset_metadata.metadata_field_id')
            ->whereIn('asset_metadata.id', $ids)
            ->whereIn('metadata_fields.key', ['starred', 'quality_rating'])
            ->select('asset_metadata.asset_id', 'metadata_fields.key', 'asset_metadata.value_json')
            ->get();

        $byAsset = [];
        foreach ($rows as $row) {
            $assetId = $row->asset_id;
            $key = $row->key;
            $value = json_decode($row->value_json, true);
            if (!isset($byAsset[$assetId])) {
                $byAsset[$assetId] = [];
            }
            $byAsset[$assetId][$key] = $value;
        }

        $assetIds = array_keys($byAsset);
        if ($limit !== null) {
            $assetIds = array_slice($assetIds, 0, $limit);
        }

        $updated = 0;
        foreach ($assetIds as $assetId) {
            $asset = Asset::find($assetId);
            if (!$asset) {
                continue;
            }
            $values = $byAsset[$assetId];
            $meta = $asset->metadata ?? [];
            $changed = false;
            foreach (['starred', 'quality_rating'] as $key) {
                if (!isset($values[$key])) {
                    continue;
                }
                $v = $values[$key];
                // STARRED CANONICAL: Store starred as strict boolean in assets.metadata
                if ($key === 'starred') {
                    $v = ($v === true || $v === 'true' || $v === 1 || $v === '1') ? true : false;
                }
                $current = $meta[$key] ?? null;
                if ($current !== $v) {
                    $meta[$key] = $v;
                    $changed = true;
                }
            }
            if ($changed && !$dryRun) {
                $asset->metadata = $meta;
                $asset->save();
                $updated++;
            } elseif ($changed) {
                $updated++;
            }
        }

        if ($dryRun) {
            $this->info("Dry run: would update {$updated} asset(s).");
        } else {
            $this->info("Updated {$updated} asset(s).");
        }

        return 0;
    }
}
