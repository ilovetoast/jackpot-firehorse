<?php

namespace App\Services\BrandIntelligence;

use App\Contracts\ImageEmbeddingServiceInterface;
use App\Models\Asset;
use Illuminate\Support\Facades\Log;

/**
 * Produces an embedding vector for a detected logo region on an asset.
 *
 * Pipeline:
 *   logo_presence.region (VLM) -> LogoRegionCropService -> local PNG
 *   -> ImageEmbeddingService::embedLocalImage -> normalized vector
 *
 * Callers persist the result (e.g. on breakdown_json.logo_crop.embedding_vector)
 * and pass it into IdentityEvaluator in preference to the full-asset embedding
 * for logo-reference similarity.
 */
final class LogoRegionEmbeddingService
{
    public function __construct(
        private LogoRegionCropService $cropService,
        private ImageEmbeddingServiceInterface $embeddingService,
    ) {}

    /**
     * @param  array<string, mixed>|null  $logoPresence  creative_signals.logo_presence
     * @return array{vector: list<float>, region: array<string, float>}|null
     */
    public function embedFromLogoPresence(Asset $asset, ?array $logoPresence): ?array
    {
        if (! is_array($logoPresence) || ! ($logoPresence['present'] ?? false)) {
            return null;
        }
        $region = is_array($logoPresence['region'] ?? null) ? $logoPresence['region'] : null;
        if ($region === null) {
            return null;
        }

        $confidence = isset($logoPresence['confidence']) && is_numeric($logoPresence['confidence'])
            ? (float) $logoPresence['confidence']
            : 0.0;
        if ($confidence < 0.4) {
            // Low VLM confidence in the logo region is not worth an extra API round-trip.
            return null;
        }

        $cropPath = $this->cropService->cropRegion($asset, $region);
        if ($cropPath === null) {
            return null;
        }

        try {
            $vector = $this->embeddingService->embedLocalImage($cropPath);
            if ($vector === []) {
                return null;
            }

            return [
                'vector' => array_values(array_map('floatval', $vector)),
                'region' => [
                    'x' => (float) ($region['x'] ?? 0),
                    'y' => (float) ($region['y'] ?? 0),
                    'w' => (float) ($region['w'] ?? 0),
                    'h' => (float) ($region['h'] ?? 0),
                ],
            ];
        } catch (\Throwable $e) {
            Log::warning('[LogoRegionEmbeddingService] Embedding failed', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        } finally {
            @unlink($cropPath);
        }
    }
}
