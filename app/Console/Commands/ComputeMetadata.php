<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Services\ComputedMetadataService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Compute Metadata Command
 *
 * Phase 5: Retroactively compute metadata for assets.
 *
 * Usage:
 *   php artisan metadata:compute              # Compute for all image assets
 *   php artisan metadata:compute {asset_id}   # Compute for specific asset
 */
class ComputeMetadata extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'metadata:compute {asset_id? : Optional asset ID to compute for a specific asset}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Compute technical metadata for assets (orientation, color_space, resolution_class)';

    /**
     * Execute the console command.
     */
    public function handle(ComputedMetadataService $service): int
    {
        $assetId = $this->argument('asset_id');

        if ($assetId) {
            // Compute for specific asset
            $asset = Asset::find($assetId);
            if (!$asset) {
                $this->error("Asset not found: {$assetId}");
                return Command::FAILURE;
            }

            $this->info("Computing metadata for asset: {$assetId}");
            try {
                $service->computeMetadata($asset);
                $this->info("✓ Metadata computed successfully");
            } catch (\Exception $e) {
                $this->error("Failed to compute metadata: " . $e->getMessage());
                Log::error('[ComputeMetadata] Failed to compute metadata', [
                    'asset_id' => $assetId,
                    'error' => $e->getMessage(),
                ]);
                return Command::FAILURE;
            }
        } else {
            // Compute for all image assets
            $this->info("Computing metadata for all image assets...");

            $query = Asset::where('status', \App\Enums\AssetStatus::VISIBLE)
                ->whereNull('deleted_at')
                ->where(function ($q) {
                    $q->where('mime_type', 'like', 'image/%')
                        ->orWhereIn('mime_type', ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp']);
                });

            $total = $query->count();
            $this->info("Found {$total} image assets");

            if ($total === 0) {
                $this->info("No image assets found");
                return Command::SUCCESS;
            }

            $bar = $this->output->createProgressBar($total);
            $bar->start();

            $successCount = 0;
            $failCount = 0;

            $query->chunk(100, function ($assets) use ($service, $bar, &$successCount, &$failCount) {
                foreach ($assets as $asset) {
                    try {
                        $service->computeMetadata($asset);
                        $successCount++;
                    } catch (\Exception $e) {
                        $failCount++;
                        Log::error('[ComputeMetadata] Failed to compute metadata', [
                            'asset_id' => $asset->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                    $bar->advance();
                }
            });

            $bar->finish();
            $this->newLine();
            $this->info("Completed: {$successCount} succeeded, {$failCount} failed");
        }

        return Command::SUCCESS;
    }
}
