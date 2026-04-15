<?php

namespace App\Services\BrandIntelligence;

use App\Contracts\ImageEmbeddingServiceInterface;
use App\Enums\MediaType;
use App\Enums\PdfBrandIntelligenceScanMode;
use App\Models\Asset;
use App\Models\AssetEmbedding;
use App\Services\ImageEmbeddingService;
use App\Support\AssetVariant;
use App\Support\DeliveryContext;
use Illuminate\Support\Facades\Log;

/**
 * Ensures an asset has a visual embedding row (Vision / CLIP pipeline) for EBI similarity.
 * Used when scoring runs before or without the normal analysis_status-gated embedding job.
 */
final class AssetEmbeddingEnsureService
{
    public function __construct(
        protected ImageEmbeddingServiceInterface $embeddingService,
        protected VisualEvaluationSourceResolver $visualEvaluationSourceResolver,
    ) {}

    /**
     * For image assets, generate and persist embedding when missing.
     * Non-images: returns existing row if any, otherwise null.
     */
    public function ensure(
        Asset $asset,
        PdfBrandIntelligenceScanMode $pdfScanMode = PdfBrandIntelligenceScanMode::Standard,
    ): ?AssetEmbedding {
        $existing = AssetEmbedding::query()->where('asset_id', $asset->id)->first();
        if ($existing && ! empty($existing->embedding_vector)) {
            return $existing;
        }

        $allowEmbed = ImageEmbeddingService::isImageMimeType((string) ($asset->mime_type ?? ''), $asset->original_filename)
            || $this->visualEvaluationSourceResolver->assetHasRenderableRaster($asset);

        if (! $allowEmbed) {
            Log::info('[EBI] Skipping embedding ensure for asset without image-like raster', [
                'asset_id' => $asset->id,
                'mime_type' => $asset->mime_type,
            ]);

            return $existing;
        }

        try {
            $vector = $this->embedVectorForAsset($asset, $pdfScanMode);
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

    /**
     * @return list<array<float>>
     */
    protected function collectPdfPageEmbeddingVectors(Asset $asset, int $maxPages): array
    {
        $catalog = PdfBrandIntelligencePageRasterCatalog::discoverRastersByPage($asset, $this->visualEvaluationSourceResolver);
        $plan = PdfBrandIntelligencePageSelector::select($asset, $catalog, max(1, $maxPages));
        $vectors = [];
        foreach ($plan['entries'] as $entry) {
            $page = (int) ($entry['page'] ?? 0);
            if ($page < 1) {
                continue;
            }
            $url = $asset->deliveryUrl(AssetVariant::PDF_PAGE, DeliveryContext::AUTHENTICATED, ['page' => $page]);
            if (! is_string($url) || trim($url) === '') {
                continue;
            }
            $vec = $this->embeddingService->embedAsset($asset, $url);
            if ($vec !== []) {
                $vectors[] = array_values(array_map('floatval', $vec));
            }
        }

        return $vectors;
    }

    /**
     * @param  list<list<float>>  $vectors
     * @return list<float>
     */
    protected static function meanL2NormalizeVectors(array $vectors): array
    {
        if ($vectors === []) {
            return [];
        }
        if (count($vectors) === 1) {
            return $vectors[0];
        }
        $dim = count($vectors[0]);
        $usable = [];
        foreach ($vectors as $vec) {
            if (count($vec) === $dim) {
                $usable[] = $vec;
            }
        }
        if ($usable === []) {
            return [];
        }
        $acc = array_fill(0, $dim, 0.0);
        foreach ($usable as $vec) {
            foreach ($vec as $i => $v) {
                $acc[$i] += (float) $v;
            }
        }
        $n = count($usable);
        foreach ($acc as $i => $v) {
            $acc[$i] = $v / max(1, $n);
        }
        $norm = sqrt(array_sum(array_map(static fn (float $x): float => $x * $x, $acc)));
        if ($norm < 1e-10) {
            return $acc;
        }

        return array_map(static fn (float $x): float => $x / $norm, $acc);
    }

    /**
     * @return list<float>|array{}
     */
    protected function embedVectorForAsset(Asset $asset, PdfBrandIntelligenceScanMode $pdfScanMode): array
    {
        $mime = strtolower((string) ($asset->mime_type ?? ''));
        if (MediaType::fromMime($mime) === MediaType::PDF) {
            $pageVectors = $this->collectPdfPageEmbeddingVectors($asset, $pdfScanMode->maxPdfPagesForSelection());
            if (count($pageVectors) > 1) {
                $merged = self::meanL2NormalizeVectors($pageVectors);
                if ($merged !== []) {
                    return $merged;
                }
            }
        }

        return $this->embeddingService->embedAsset($asset);
    }
}
