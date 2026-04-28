<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Studio\LayerExtraction\Contracts\SamSegmentationClientInterface;
use App\Studio\LayerExtraction\Sam\NullSamSegmentationClient;
use App\Support\EditorAssetOriginalBytesLoader;
use Illuminate\Console\Command;
use Throwable;

class StudioDebugLayerExtractionSamCommand extends Command
{
    protected $signature = 'studio:debug-layer-extraction-sam
                            {assetId : Source asset id (raster) to run a one-off auto-segmentation probe}';

    protected $description = 'Call Fal SAM auto-segmentation once and print a sanitized summary (no Studio session).';

    public function handle(): int
    {
        $id = (int) $this->argument('assetId');
        $client = app(SamSegmentationClientInterface::class);
        if ($client instanceof NullSamSegmentationClient) {
            $this->error('Fal SAM client is not bound (set FAL_KEY and STUDIO_LAYER_EXTRACTION_SAM_PROVIDER=fal).');

            return self::FAILURE;
        }
        if (! (bool) config('studio_layer_extraction.sam.enabled', false)) {
            $this->warn('STUDIO_LAYER_EXTRACTION_SAM_ENABLED is false; client may still run for this probe.');
        }
        $asset = Asset::query()->find($id);
        if ($asset === null) {
            $this->error("Asset {$id} not found.");

            return self::FAILURE;
        }
        try {
            $bytes = EditorAssetOriginalBytesLoader::loadFromStorage($asset);
        } catch (Throwable $e) {
            $this->error('Could not load bytes: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->info('Running autoSegment (this may call Fal; no Studio layer session is created)…');
        try {
            $r = $client->autoSegment($bytes, [
                'image_mime' => 'image/png',
                'fal_log_mode' => 'auto',
                'timeout_seconds' => (int) config('studio_layer_extraction.sam.timeout', 120),
            ]);
        } catch (Throwable $e) {
            $this->error('Provider error: '.(string) $e->getMessage());

            return self::FAILURE;
        }
        $n = count($r->segments);
        $this->info("OK: segments={$n} model={$r->model} engine={$r->engine} duration_ms={$r->durationMs}");

        return self::SUCCESS;
    }
}
