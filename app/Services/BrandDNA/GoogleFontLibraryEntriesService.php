<?php

namespace App\Services\BrandDNA;

use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Category;
use App\Support\Typography\GoogleFontStylesheetHelper;

/**
 * Virtual grid rows for Google Fonts declared in Brand Guidelines (no DAM binary).
 */
final class GoogleFontLibraryEntriesService
{
    /**
     * Build frontend asset-shaped rows for the Fonts category from active Brand DNA typography.
     *
     * @return list<array<string, mixed>>
     */
    public function virtualAssetsForFontsCategory(Brand $brand, Category $fontsCategory): array
    {
        if ($fontsCategory->slug !== 'fonts' || $fontsCategory->asset_type !== AssetType::ASSET) {
            return [];
        }

        $brand->loadMissing('brandModel.activeVersion');
        $version = $brand->brandModel?->activeVersion;
        if (! $version) {
            return [];
        }

        $payload = $version->model_payload ?? [];
        $typography = $payload['typography'] ?? null;
        if (! is_array($typography)) {
            return [];
        }

        $fonts = $typography['fonts'] ?? null;
        if (! is_array($fonts) || $fonts === []) {
            return [];
        }

        $existingTitles = Asset::query()
            ->where('tenant_id', $brand->tenant_id)
            ->where('brand_id', $brand->id)
            ->where('type', AssetType::ASSET)
            ->whereNotNull('metadata')
            ->whereRaw(
                'CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.category_id")) AS UNSIGNED) = ?',
                [$fontsCategory->id]
            )
            ->pluck('title')
            ->map(fn ($t) => strtolower(trim((string) $t)))
            ->filter()
            ->all();

        $existingTitles = array_flip($existingTitles);

        $out = [];
        $seenFamilies = [];
        $index = 0;

        foreach ($fonts as $fontEntry) {
            if (! is_array($fontEntry)) {
                continue;
            }
            if (($fontEntry['source'] ?? '') !== 'google') {
                continue;
            }

            $family = trim((string) ($fontEntry['name'] ?? ''));
            if ($family === '') {
                continue;
            }

            $norm = strtolower($family);
            if (isset($seenFamilies[$norm])) {
                continue;
            }
            $seenFamilies[$norm] = true;

            if (isset($existingTitles[$norm])) {
                continue;
            }

            $stylesheetUrl = GoogleFontStylesheetHelper::stylesheetUrlForGoogleFontEntry($fontEntry);
            if ($stylesheetUrl === null) {
                continue;
            }

            $role = strtolower((string) ($fontEntry['role'] ?? 'primary'));
            $fontRole = $this->mapFontManagerRoleToFontRole($role);

            $id = 'google-font-'.$index.'-'.substr(sha1($brand->id.'|'.$family), 0, 10);
            $index++;

            // Specimen page on fonts.google.com (users download/install from Google, not from our DAM)
            $googleFontSpecimenUrl = 'https://fonts.google.com/specimen/'.rawurlencode($family);

            $out[] = [
                'id' => $id,
                'title' => $family,
                'original_filename' => null,
                'mime_type' => 'application/x-font-google-host',
                'file_extension' => null,
                'status' => 'visible',
                'size_bytes' => null,
                'created_at' => null,
                'metadata' => [
                    'category_id' => $fontsCategory->id,
                    'fields' => [
                        'font_role' => $fontRole,
                    ],
                ],
                'starred' => false,
                'category' => [
                    'id' => $fontsCategory->id,
                    'name' => $fontsCategory->name,
                    'slug' => 'fonts',
                    'ebi_enabled' => $fontsCategory->isEbiEnabled(),
                ],
                'user_id' => null,
                'uploaded_by' => null,
                'thumbnail_small' => null,
                'thumbnail_medium' => null,
                'thumbnail_large' => null,
                'thumbnail_preview' => null,
                'original' => null,
                'thumbnail_version' => null,
                'thumbnail_url' => null,
                'preview_thumbnail_url' => null,
                'final_thumbnail_url' => null,
                'thumbnail_url_large' => null,
                'thumbnail_status' => 'skipped',
                'thumbnail_error' => null,
                'pdf_page_count' => null,
                'pdf_pages_rendered' => false,
                'published_at' => null,
                'is_published' => true,
                'published_by' => null,
                'archived_at' => null,
                'archived_by' => null,
                'video_preview_url' => null,
                'video_poster_url' => null,
                'analysis_status' => 'complete',
                'health_status' => 'healthy',
                'brand_intelligence' => null,
                'reference_promotion' => null,
                'is_virtual_google_font' => true,
                'google_font_stylesheet_url' => $stylesheetUrl,
                'google_font_family' => $family,
                'google_font_specimen_url' => $googleFontSpecimenUrl,
                'google_font_role_label' => $role !== '' ? $role : null,
            ];
        }

        return $out;
    }

    private function mapFontManagerRoleToFontRole(string $role): string
    {
        return match ($role) {
            'secondary', 'body' => 'body_copy',
            default => 'headline',
        };
    }
}
