<?php

namespace App\Services\BrandDNA;

use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\BrandModelVersion;
use App\Models\Category;
use App\Services\AssetPublicationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * When Brand Guidelines typography lists licensed font files, sync those assets into the
 * hidden "Fonts" library category and set {@see font_role} from FontManager roles.
 */
final class BrandFontLibrarySyncService
{
    public function __construct(
        protected AssetPublicationService $assetPublicationService
    ) {}

    public function syncFromVersion(Brand $brand, BrandModelVersion $version): void
    {
        $payload = $version->model_payload ?? [];
        $typography = $payload['typography'] ?? null;
        if (! is_array($typography)) {
            return;
        }

        $fonts = $typography['fonts'] ?? null;
        if (! is_array($fonts) || $fonts === []) {
            return;
        }

        $fontsCategory = Category::query()
            ->where('tenant_id', $brand->tenant_id)
            ->where('brand_id', $brand->id)
            ->where('asset_type', AssetType::ASSET)
            ->where('slug', 'fonts')
            ->first();

        if (! $fontsCategory) {
            Log::info('[BrandFontLibrarySync] Fonts category not found for brand', ['brand_id' => $brand->id]);

            return;
        }

        foreach ($fonts as $fontEntry) {
            if (! is_array($fontEntry)) {
                continue;
            }
            $familyName = trim((string) ($fontEntry['name'] ?? ''));
            $role = strtolower((string) ($fontEntry['role'] ?? 'primary'));
            $fontRole = $this->mapFontManagerRoleToFontRole($role);

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

                if (! $this->assetIsFontBinary($asset)) {
                    continue;
                }

                try {
                    $this->applyFontToLibrary($asset, $brand, $fontsCategory, $familyName, $fontRole);
                } catch (\Throwable $e) {
                    Log::warning('[BrandFontLibrarySync] Failed to sync font asset', [
                        'asset_id' => $asset->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    private function applyFontToLibrary(
        Asset $asset,
        Brand $brand,
        Category $fontsCategory,
        string $familyName,
        string $fontRole
    ): void {
        DB::transaction(function () use ($asset, $fontsCategory, $familyName, $fontRole) {
            if ($asset->type === AssetType::REFERENCE) {
                $asset->type = AssetType::ASSET;
                $asset->builder_staged = false;
                $asset->intake_state = 'normal';
            }

            $meta = $asset->metadata ?? [];
            $meta['category_id'] = $fontsCategory->id;
            $fields = isset($meta['fields']) && is_array($meta['fields']) ? $meta['fields'] : [];
            $fields['font_role'] = $fontRole;
            $meta['fields'] = $fields;

            if ($familyName !== '' && (empty($asset->title) || $asset->title === 'Untitled Asset' || $asset->title === 'Unknown')) {
                $asset->title = $familyName;
            }

            $asset->metadata = $meta;
            $asset->save();

            // Publishing requires an authenticated user for Gate; skip when running without a session.
            if (auth()->check() && ! $asset->isPublished()) {
                try {
                    $this->assetPublicationService->publish($asset, auth()->user());
                } catch (\Throwable $e) {
                    Log::warning('[BrandFontLibrarySync] Publish skipped', [
                        'asset_id' => $asset->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });
    }

    /**
     * FontManager roles → simple library roles (headline / body).
     */
    private function mapFontManagerRoleToFontRole(string $role): string
    {
        return match ($role) {
            'secondary', 'body' => 'body_copy',
            default => 'headline',
        };
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

    /**
     * Same URL patterns as {@see \App\Http\Controllers\Editor\EditorBrandContextController::parseAssetIdFromFontUrl}.
     */
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
