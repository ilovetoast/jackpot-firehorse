<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Services\Color\HueClusterService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillHueClustersCommand extends Command
{
    protected $signature = 'assets:backfill-hue-clusters
                            {--chunk=500 : Number of assets to process per chunk}
                            {--dry-run : Show what would be updated without making changes}';

    protected $description = 'Backfill dominant_hue_group for assets with dominant_colors but missing hue group';

    public function handle(HueClusterService $hueClusterService): int
    {
        $chunkSize = (int) $this->option('chunk');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Dry run mode - no changes will be made.');
        }

        $dominantColorsFieldId = DB::table('metadata_fields')->where('key', 'dominant_colors')->value('id');
        if (!$dominantColorsFieldId) {
            $this->error('Metadata field dominant_colors not found.');

            return 1;
        }

        $hueGroupField = DB::table('metadata_fields')->where('key', 'dominant_hue_group')->first();
        if (!$hueGroupField) {
            $this->error('Metadata field dominant_hue_group not found. Run migrations and seeders.');

            return 1;
        }

        $query = Asset::query()
            ->whereNotNull('metadata')
            ->where(function ($q) {
                $q->whereNull('dominant_hue_group')
                    ->orWhere('dominant_hue_group', '');
            });

        $total = $query->count();
        if ($total === 0) {
            $this->info('No assets need backfilling.');

            return 0;
        }

        $this->info("Found {$total} assets to process.");

        $updated = 0;
        $skipped = 0;

        $query->chunkById($chunkSize, function ($assets) use ($hueClusterService, $hueGroupField, $dominantColorsFieldId, &$updated, &$skipped, $dryRun) {
            foreach ($assets as $asset) {
                $dominantColors = null;

                $am = DB::table('asset_metadata')
                    ->where('asset_id', $asset->id)
                    ->where('metadata_field_id', $dominantColorsFieldId)
                    ->first();

                if ($am && $am->value_json) {
                    $dominantColors = json_decode($am->value_json, true);
                }

                if (empty($dominantColors) || !is_array($dominantColors)) {
                    $dominantColors = $asset->metadata['dominant_colors'] ?? null;
                }

                if (empty($dominantColors) || !is_array($dominantColors)) {
                    $skipped++;
                    continue;
                }

                $topColor = $dominantColors[0] ?? null;
                $hex = is_array($topColor) ? ($topColor['hex'] ?? null) : null;
                if (!$hex || !is_string($hex)) {
                    $skipped++;
                    continue;
                }

                $clusterKey = $hueClusterService->assignClusterFromHex($hex);
                if ($clusterKey === null) {
                    $skipped++;
                    continue;
                }

                if (!$dryRun) {
                    $asset->update(['dominant_hue_group' => $clusterKey]);

                    $valueJson = json_encode($clusterKey);
                    $existing = DB::table('asset_metadata')
                        ->where('asset_id', $asset->id)
                        ->where('metadata_field_id', $hueGroupField->id)
                        ->first();

                    if ($existing) {
                        DB::table('asset_metadata')
                            ->where('id', $existing->id)
                            ->update([
                                'value_json' => $valueJson,
                                'source' => 'system',
                                'updated_at' => now(),
                            ]);
                    } else {
                        DB::table('asset_metadata')->insert([
                            'asset_id' => $asset->id,
                            'metadata_field_id' => $hueGroupField->id,
                            'value_json' => $valueJson,
                            'source' => 'system',
                            'confidence' => 0.95,
                            'producer' => 'system',
                            'approved_at' => now(),
                            'approved_by' => null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                $updated++;
            }

            $this->output->write('.');
        });

        $this->newLine();
        $this->info("Updated: {$updated}, Skipped: {$skipped}");

        if ($dryRun && $updated > 0) {
            $this->warn("Dry run: {$updated} assets would have been updated.");
        }

        return 0;
    }
}
