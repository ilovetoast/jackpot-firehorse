<?php

namespace App\Jobs;

use App\Contracts\ImageEmbeddingServiceInterface;
use App\Models\Asset;
use App\Models\AssetEmbedding;
use App\Jobs\ScoreAssetComplianceJob;
use App\Models\BrandVisualReference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Generate Asset Embedding Job
 *
 * Downloads image, calls embedding API, stores vector in asset_embeddings.
 * Optionally updates BrandVisualReference.embedding_vector when ref is selected.
 *
 * Guards:
 * - Only process image mime types
 * - Skip if embedding already exists (unless updating a brand visual reference)
 */
class GenerateAssetEmbeddingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [60, 300, 900];

    /**
     * @param  string  $assetId  Asset UUID
     * @param  int|null  $brandVisualReferenceId  When set, also update this BrandVisualReference's embedding_vector
     */
    public function __construct(
        public readonly string $assetId,
        public readonly ?int $brandVisualReferenceId = null
    ) {}

    public function handle(ImageEmbeddingServiceInterface $embeddingService): void
    {
        $asset = Asset::find($this->assetId);
        if (! $asset) {
            Log::warning('[GenerateAssetEmbeddingJob] Asset not found', ['asset_id' => $this->assetId]);

            return;
        }

        if (! \App\Services\ImageEmbeddingService::isImageMimeType($asset->mime_type)) {
            Log::info('[GenerateAssetEmbeddingJob] Skipping non-image asset', [
                'asset_id' => $asset->id,
                'mime_type' => $asset->mime_type,
            ]);

            return;
        }

        $existing = AssetEmbedding::where('asset_id', $asset->id)->first();
        if ($existing && ! $this->brandVisualReferenceId) {
            Log::info('[GenerateAssetEmbeddingJob] Embedding already exists, skipping', [
                'asset_id' => $asset->id,
            ]);

            return;
        }

        // Guard: only mutate analysis_status when in expected previous state
        $expectedStatus = 'generating_embedding';
        $currentStatus = $asset->analysis_status ?? 'uploading';
        if ($currentStatus !== $expectedStatus) {
            Log::warning('[GenerateAssetEmbeddingJob] Invalid analysis_status transition aborted', [
                'asset_id' => $asset->id,
                'expected' => $expectedStatus,
                'actual' => $currentStatus,
            ]);

            return;
        }

        $vector = $existing?->embedding_vector ?? $embeddingService->embedAsset($asset);
        if (empty($vector)) {
            Log::warning('[GenerateAssetEmbeddingJob] Empty embedding returned', ['asset_id' => $asset->id]);

            return;
        }

        $model = config('services.image_embedding.model', 'clip-vit-base-patch32');

        if (! $existing) {
            AssetEmbedding::updateOrCreate(
                ['asset_id' => $asset->id],
                [
                    'embedding_vector' => $vector,
                    'model' => $model,
                ]
            );
            // 4. When embedding saved: set analysis_status = 'scoring'
            $asset->update(['analysis_status' => 'scoring']);
            \App\Services\AnalysisStatusLogger::log($asset, 'generating_embedding', 'scoring', 'GenerateAssetEmbeddingJob');
            ScoreAssetComplianceJob::dispatch($asset->id);
        }

        if ($this->brandVisualReferenceId) {
            $ref = BrandVisualReference::find($this->brandVisualReferenceId);
            if ($ref && $ref->asset_id === $asset->id) {
                $ref->update(['embedding_vector' => $vector]);
            }
        }

        Log::info('[GenerateAssetEmbeddingJob] Embedding generated', [
            'asset_id' => $asset->id,
            'dimensions' => count($vector),
        ]);
    }
}
