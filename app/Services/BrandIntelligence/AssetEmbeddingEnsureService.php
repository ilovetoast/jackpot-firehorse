<?php

namespace App\Services\BrandIntelligence;

use App\Contracts\ImageEmbeddingServiceInterface;
use App\Models\Asset;
use App\Models\AssetEmbedding;
use App\Services\ImageEmbeddingService;
use Illuminate\Support\Facades\Log;

/**
 * Ensures an asset has a visual embedding row (Vision / CLIP pipeline) for EBI similarity.
 * Used when scoring runs before or without the normal analysis_status-gated embedding job.
 */
final class AssetEmbeddingEnsureService
{
    public function __construct(
        protected ImageEmbeddingServiceInterface $embeddingService
    ) {}

    /**
     * For image assets, generate and persist embedding when missing.
     * Non-images: returns existing row if any, otherwise null.
     */
    public function ensure(Asset $asset): ?AssetEmbedding
    {
        $existing = AssetEmbedding::query()->where('asset_id', $asset->id)->first();
        if ($existing && ! empty($existing->embedding_vector)) {
            return $existing;
        }

        if (! ImageEmbeddingService::isImageMimeType((string) ($asset->mime_type ?? ''), $asset->original_filename)) {
            Log::info('[EBI] Skipping embedding ensure for non-image asset', [
                'asset_id' => $asset->id,
                'mime_type' => $asset->mime_type,
            ]);

            return $existing;
        }

        try {
            $vector = $this->embeddingService->embedAsset($asset);
            if (empty($vector)) {
                Log::warning('[EBI] Embedding ensure returned empty vector', ['asset_id' => $asset->id]);

                return null;
            }

            $model = config('services.image_embedding.model', 'clip-vit-base-patch32');

            return AssetEmbedding::updateOrCreate(
                ['asset_id' => $asset->id],
                [
                    'embedding_vector' => $vector,
                    'model' => $model,
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('[EBI] Embedding ensure failed', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
