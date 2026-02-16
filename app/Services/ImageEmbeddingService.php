<?php

namespace App\Services;

use App\Contracts\ImageEmbeddingServiceInterface;
use App\Models\Asset;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Image Embedding Service
 *
 * Generates embedding vectors for images. When IMAGE_EMBEDDING_API_URL is configured,
 * calls the external API. Otherwise returns a deterministic placeholder vector for
 * development/testing (same asset always gets same vector).
 */
class ImageEmbeddingService implements \App\Contracts\ImageEmbeddingServiceInterface
{
    protected const EMBEDDING_DIMENSION = 512;

    protected const IMAGE_MIME_PREFIXES = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/avif',
        'image/tiff',
        'image/tif',
        'image/bmp',
        'image/svg+xml',
    ];

    public function __construct(
        protected ?string $apiUrl = null,
        protected string $model = 'clip-vit-base-patch32'
    ) {
        $this->apiUrl = $apiUrl ?? config('services.image_embedding.url');
        $this->model = config('services.image_embedding.model', $this->model);
    }

    /**
     * {@inheritdoc}
     */
    public function embedAsset(Asset $asset): array
    {
        if ($this->apiUrl) {
            return $this->embedViaApi($asset);
        }

        return $this->embedPlaceholder($asset);
    }

    /**
     * Call external embedding API with image URL.
     */
    protected function embedViaApi(Asset $asset): array
    {
        $url = $asset->medium_thumbnail_url;
        if (! $url) {
            Log::warning('[ImageEmbeddingService] No image URL available for asset', [
                'asset_id' => $asset->id,
            ]);

            return $this->embedPlaceholder($asset);
        }

        try {
            $response = Http::timeout(30)
                ->post($this->apiUrl, [
                    'image_url' => $url,
                    'model' => $this->model,
                ]);

            if ($response->failed()) {
                Log::warning('[ImageEmbeddingService] API call failed', [
                    'asset_id' => $asset->id,
                    'status' => $response->status(),
                ]);

                return $this->embedPlaceholder($asset);
            }

            $data = $response->json();
            $vector = $data['embedding'] ?? $data['vector'] ?? $data;

            if (! is_array($vector)) {
                return $this->embedPlaceholder($asset);
            }

            return array_map('floatval', array_values($vector));
        } catch (\Throwable $e) {
            Log::warning('[ImageEmbeddingService] API exception', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            return $this->embedPlaceholder($asset);
        }
    }

    /**
     * Generate deterministic placeholder vector (for dev/test when API not configured).
     */
    protected function embedPlaceholder(Asset $asset): array
    {
        $hash = md5($asset->id . '-embedding');
        $vector = [];
        for ($i = 0; $i < self::EMBEDDING_DIMENSION; $i++) {
            $byte = hexdec(substr($hash, $i % 32, 1)) / 15.0;
            $vector[] = ($byte - 0.5) * 2;
        }

        return $this->normalize($vector);
    }

    protected function normalize(array $v): array
    {
        $norm = sqrt(array_sum(array_map(fn ($x) => $x * $x, $v)));
        if ($norm < 1e-10) {
            return $v;
        }

        return array_map(fn ($x) => $x / $norm, $v);
    }

    /**
     * Check if asset has an image mime type suitable for embedding.
     */
    public static function isImageMimeType(?string $mimeType): bool
    {
        if (! $mimeType) {
            return false;
        }
        $mime = strtolower(trim($mimeType));

        return in_array($mime, self::IMAGE_MIME_PREFIXES, true)
            || str_starts_with($mime, 'image/');
    }
}
