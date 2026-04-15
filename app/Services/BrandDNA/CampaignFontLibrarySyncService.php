<?php

namespace App\Services\BrandDNA;

use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Collection;
use App\Services\AssetPublicationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * When Campaign Identity typography lists font files (DAM URLs), sync those assets into the
 * Fonts library category and set {@see font_role} to {@code campaign}.
 */
final class CampaignFontLibrarySyncService
{
    public function __construct(
        protected AssetPublicationService $assetPublicationService
    ) {}

    public function syncFromCollection(Collection $collection, array $identityPayload): void
    {
        $typography = $identityPayload['typography'] ?? null;
        if (! is_array($typography)) {
            return;
        }

        $fonts = $typography['fonts'] ?? null;
        if (! is_array($fonts) || $fonts === []) {
            return;
        }

        $brand = $collection->brand;
        if (! $brand) {
            return;
        }

        $fontsCategory = Category::query()
            ->where('tenant_id', $brand->tenant_id)
            ->where('brand_id', $brand->id)
            ->where('asset_type', AssetType::ASSET)
            ->where('slug', 'fonts')
            ->first();

        if (! $fontsCategory) {
            Log::info('[CampaignFontLibrarySync] Fonts category not found for brand', ['brand_id' => $brand->id]);

            return;
        }

        foreach ($fonts as $fontEntry) {
            if (! is_array($fontEntry)) {
                continue;
            }
            $familyName = trim((string) ($fontEntry['name'] ?? ''));
            $urls = $fontEntry['file_urls'] ?? [];
            if (! is_array($urls)) {
                continue;
            }

            foreach ($urls as $u) {
                if (! is_string($u) || $u === '') {
                    continue;
                }
                $assetId = $this->parseAssetIdFromFontUrl($u);
                if ($assetId === null) {
                    continue;
                }

                $asset = Asset::query()
                    ->where('id', $assetId)
                    ->where('brand_id', $brand->id)
                    ->where('tenant_id', $brand->tenant_id)
                    ->first();

                if (! $asset) {
                    continue;
                }

                if (! $collection->assets()->where('assets.id', $asset->id)->exists()) {
                    Log::info('[CampaignFontLibrarySync] Skip asset not in collection', [
                        'collection_id' => $collection->id,
                        'asset_id' => $asset->id,
                    ]);

                    continue;
                }

                if (! $this->assetIsFontBinary($asset)) {
                    continue;
                }

                try {
                    $this->applyFontToLibrary($asset, $fontsCategory, $familyName);
                } catch (\Throwable $e) {
                    Log::warning('[CampaignFontLibrarySync] Failed to sync font asset', [
                        'asset_id' => $asset->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    private function applyFontToLibrary(
        Asset $asset,
        Category $fontsCategory,
        string $familyName
    ): void {
        DB::transaction(function () use ($asset, $fontsCategory, $familyName) {
            if ($asset->type === AssetType::REFERENCE) {
                $asset->type = AssetType::ASSET;
                $asset->builder_staged = false;
                $asset->intake_state = 'normal';
            }

            $meta = $asset->metadata ?? [];
            $meta['category_id'] = $fontsCategory->id;
            $fields = isset($meta['fields']) && is_array($meta['fields']) ? $meta['fields'] : [];
            $fields['font_role'] = 'campaign';
            $meta['fields'] = $fields;

            if ($familyName !== '' && (empty($asset->title) || $asset->title === 'Untitled Asset' || $asset->title === 'Unknown')) {
                $asset->title = $familyName;
            }

            $asset->metadata = $meta;
            $asset->save();

            if (auth()->check() && ! $asset->isPublished()) {
                try {
                    $this->assetPublicationService->publish($asset, auth()->user());
                } catch (\Throwable $e) {
                    Log::warning('[CampaignFontLibrarySync] Publish skipped', [
                        'asset_id' => $asset->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });
    }

    private function assetIsFontBinary(Asset $asset): bool
    {
        $mime = strtolower((string) ($asset->mime_type ?? ''));
        if (str_starts_with($mime, 'font/')) {
            return true;
        }
        if (in_array($mime, [
            'application/font-woff',
            'application/font-woff2',
            'application/vnd.ms-opentype',
            'application/x-font-ttf',
            'application/x-font-otf',
        ], true)) {
            return true;
        }

        $ext = strtolower(pathinfo((string) ($asset->original_filename ?? ''), PATHINFO_EXTENSION));
        if (in_array($ext, ['woff2', 'woff', 'ttf', 'otf', 'eot'], true)) {
            return true;
        }

        return $mime === 'application/octet-stream'
            && in_array($ext, ['woff2', 'woff', 'ttf', 'otf', 'eot'], true);
    }

    private function parseAssetIdFromFontUrl(string $url): ?string
    {
        $uuid = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
        $patterns = [
            '#/assets/('.$uuid.')/(?:download|file)#',
            '#/assets/(\d+)/(?:download|file)#',
            '#/api/assets/('.$uuid.')/(?:file|download)#',
            '#/api/assets/(\d+)/(?:file|download)#',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $m)) {
                return $m[1];
            }
        }

        return null;
    }
}
