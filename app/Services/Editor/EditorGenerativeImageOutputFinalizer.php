<?php

namespace App\Services\Editor;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EditorGenerativeImagePersistService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Persists or proxies provider image refs for editor generative flows.
 * Extracted from {@see \App\Http\Controllers\Editor\Concerns\ResolvesGenerativeImageOutput} so HTTP controllers
 * and background workers can share the same behavior without routing through controllers.
 */
final class EditorGenerativeImageOutputFinalizer
{
    public const PROXY_CACHE_PREFIX = 'editor_gen_proxy:';

    /**
     * @param  array<string, mixed>  $persistContext
     * @return array{image_url: string, asset_id: string|null}
     */
    public function finalize(
        string $imageRef,
        Tenant $tenant,
        User $user,
        EditorGenerativeImagePersistService $persistService,
        array $persistContext = []
    ): array {
        if (! config('editor.generative.persist', true)) {
            Log::info('editor.generative.output', [
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'delivery' => 'proxy',
                'reason' => 'persist_disabled',
            ]);

            return [
                'image_url' => $this->registerProxyUrl($imageRef),
                'asset_id' => null,
            ];
        }

        $brand = app('brand');
        if (! $brand instanceof Brand || ! isset($brand->id)) {
            Log::info('editor.generative.persist_skipped_no_brand', [
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
            ]);
            Log::info('editor.generative.output', [
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'delivery' => 'proxy',
                'reason' => 'no_brand_context',
            ]);

            return [
                'image_url' => $this->registerProxyUrl($imageRef),
                'asset_id' => null,
            ];
        }

        try {
            $out = $persistService->persistFromProviderReference($imageRef, $tenant, $user, $brand, $persistContext);

            Log::info('editor.generative.output', [
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'brand_id' => $brand->id,
                'delivery' => 'asset',
                'asset_id' => $out['asset_id'],
            ]);

            return [
                'image_url' => $out['url'],
                'asset_id' => $out['asset_id'],
            ];
        } catch (\Throwable $e) {
            Log::warning('editor.generative.persist_failed', [
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'brand_id' => $brand->id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
            Log::info('editor.generative.output', [
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'brand_id' => $brand->id,
                'delivery' => 'proxy',
                'reason' => 'persist_exception',
            ]);

            return [
                'image_url' => $this->registerProxyUrl($imageRef),
                'asset_id' => null,
            ];
        }
    }

    private function registerProxyUrl(string $urlOrDataUrl): string
    {
        $token = bin2hex(random_bytes(16));
        Cache::put(self::PROXY_CACHE_PREFIX.$token, $urlOrDataUrl, now()->addMinutes(45));

        return route('api.editor.generate-image.proxy', ['token' => $token], absolute: true);
    }
}
