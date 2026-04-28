<?php

namespace App\Services\Fal;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Optional Fal $/s or per-call estimates; falls back to config. Does not log raw URLs.
 */
final class FalModelPricingService
{
    private const CACHE_TTL = 600;

    public function estimatedCostUsd(): ?float
    {
        $config = (string) config('studio_layer_extraction.sam.estimated_cost_usd', '');
        if (is_numeric($config) && (float) $config > 0) {
            return (float) $config;
        }
        if (! (bool) config('studio_layer_extraction.sam.pricing_api_enabled', false)) {
            return null;
        }

        return $this->fetchFromFalIfConfigured();
    }

    public function costSource(): string
    {
        if (is_numeric((string) config('studio_layer_extraction.sam.estimated_cost_usd', '')) && (float) config('studio_layer_extraction.sam.estimated_cost_usd', 0) > 0) {
            return 'configured';
        }
        if ((bool) config('studio_layer_extraction.sam.pricing_api_enabled', false)) {
            return 'fal_pricing_api';
        }

        return 'unknown';
    }

    private function fetchFromFalIfConfigured(): ?float
    {
        $key = (string) config('services.fal.key', '');
        if ($key === '') {
            return null;
        }
        $model = (string) config('services.fal.sam2_pricing_model_id', 'fal-ai/sam2/image');
        $cacheKey = 'fal_pricing:'.md5($model);
        if (Cache::has($cacheKey)) {
            return (float) Cache::get($cacheKey);
        }
        $base = (string) config('services.fal.api_base', 'https://fal.run');
        $url = rtrim($base, '/').'/api/pricing/'.ltrim($model, '/');
        $resp = Http::withHeaders(['Authorization' => 'Key '.$key])
            ->timeout(5)
            ->get($url);
        if (! $resp->ok()) {
            return null;
        }
        $j = $resp->json();
        $n = is_array($j) ? (float) ($j['base_price'] ?? $j['price'] ?? 0) : 0.0;
        if ($n <= 0) {
            return null;
        }
        Cache::put($cacheKey, $n, self::CACHE_TTL);

        return $n;
    }
}
