<?php

namespace App\Http\Controllers\Editor\Concerns;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EditorGenerativeImagePersistService;
use Illuminate\Support\Facades\Log;

trait ResolvesGenerativeImageOutput
{
    abstract protected function registerProxyUrl(string $urlOrDataUrl): string;

    /**
     * @param  array<string, mixed>  $persistContext
     *   Optional keys: composition_id, generative_layer_uuid (for versioned generative layer assets).
     * @return array{image_url: string, asset_id: string|null}
     */
    protected function finalizeGenerativeImageOutput(
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
}
