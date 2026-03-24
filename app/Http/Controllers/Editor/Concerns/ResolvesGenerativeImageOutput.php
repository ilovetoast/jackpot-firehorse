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
     * @return array{image_url: string, asset_id: string|null}
     */
    protected function finalizeGenerativeImageOutput(
        string $imageRef,
        Tenant $tenant,
        User $user,
        EditorGenerativeImagePersistService $persistService
    ): array {
        if (! config('editor.generative.persist', true)) {
            return [
                'image_url' => $this->registerProxyUrl($imageRef),
                'asset_id' => null,
            ];
        }

        $brand = app('brand');
        if (! $brand instanceof Brand || ! isset($brand->id)) {
            Log::info('editor.generative.persist_skipped_no_brand', [
                'tenant_id' => $tenant->id,
            ]);

            return [
                'image_url' => $this->registerProxyUrl($imageRef),
                'asset_id' => null,
            ];
        }

        try {
            $out = $persistService->persistFromProviderReference($imageRef, $tenant, $user, $brand);

            return [
                'image_url' => $out['url'],
                'asset_id' => $out['asset_id'],
            ];
        } catch (\Throwable $e) {
            Log::warning('editor.generative.persist_failed', [
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'image_url' => $this->registerProxyUrl($imageRef),
                'asset_id' => null,
            ];
        }
    }
}
