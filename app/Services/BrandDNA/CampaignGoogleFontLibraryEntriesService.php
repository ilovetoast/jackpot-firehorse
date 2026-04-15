<?php

namespace App\Services\BrandDNA;

use App\Enums\AssetType;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Collection;
use App\Models\CollectionCampaignIdentity;
use App\Support\Typography\GoogleFontStylesheetHelper;

/**
 * Virtual grid rows for Google Fonts declared on Campaign Identity (no DAM binary).
 */
final class CampaignGoogleFontLibraryEntriesService
{
    /**
     * Virtual rows for one collection’s campaign (Collections grid / guest view).
     *
     * @return list<array<string, mixed>>
     */
    public function virtualAssetsForCollection(Brand $brand, Category $fontsCategory, Collection $collection): array
    {
        if ($fontsCategory->slug !== 'fonts' || $fontsCategory->asset_type !== AssetType::ASSET) {
            return [];
        }

        $collection->loadMissing('campaignIdentity');
        $identity = $collection->campaignIdentity;
        if (! $identity instanceof CollectionCampaignIdentity) {
            return [];
        }

        return $this->virtualRowsForCollectionIdentity($brand, $fontsCategory, $collection, $identity);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function virtualAssetsForFontsCategory(Brand $brand, Category $fontsCategory): array
    {
        if ($fontsCategory->slug !== 'fonts' || $fontsCategory->asset_type !== AssetType::ASSET) {
            return [];
        }

        $identities = CollectionCampaignIdentity::query()
            ->whereHas('collection', function ($q) use ($brand) {
                $q->where('brand_id', $brand->id)
                    ->where('tenant_id', $brand->tenant_id);
            })
            ->with('collection:id,name,brand_id,tenant_id')
            ->get();

        $out = [];
        $seenKeys = [];

        foreach ($identities as $identity) {
            $collection = $identity->collection;
            if (! $collection) {
                continue;
            }

            foreach ($this->virtualRowsForCollectionIdentity($brand, $fontsCategory, $collection, $identity) as $row) {
                $key = $collection->id.'|'.strtolower((string) ($row['google_font_family'] ?? $row['title'] ?? ''));
                if (isset($seenKeys[$key])) {
                    continue;
                }
                $seenKeys[$key] = true;
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function virtualRowsForCollectionIdentity(
        Brand $brand,
        Category $fontsCategory,
        Collection $collection,
        CollectionCampaignIdentity $identity,
    ): array {
        $payload = $identity->identity_payload ?? [];
        $typography = $payload['typography'] ?? null;
        if (! is_array($typography)) {
            return [];
        }

        $fonts = $typography['fonts'] ?? null;
        if (! is_array($fonts) || $fonts === []) {
            return [];
        }

        $out = [];
        $seenFamilies = [];

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

            $stylesheetUrl = GoogleFontStylesheetHelper::stylesheetUrlForGoogleFontEntry($fontEntry);
            if ($stylesheetUrl === null) {
                continue;
            }

            $famKey = strtolower($family);
            if (isset($seenFamilies[$famKey])) {
                continue;
            }
            $seenFamilies[$famKey] = true;

            $role = strtolower((string) ($fontEntry['role'] ?? 'primary'));
            $roleLabel = $role !== '' ? ucfirst($role) : 'Primary';

            $id = 'campaign-google-font-'.$collection->id.'-'.substr(sha1($brand->id.'|'.$collection->id.'|'.$family), 0, 10);
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
                        'font_role' => 'campaign',
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
                'submitted_by_prostaff' => false,
                'prostaff_user_id' => null,
                'prostaff_user_name' => null,
                'is_prostaff_asset' => false,
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
                'is_campaign_collection_font' => true,
                'campaign_collection_id' => $collection->id,
                'campaign_collection_name' => $collection->name,
                'google_font_stylesheet_url' => $stylesheetUrl,
                'google_font_family' => $family,
                'google_font_specimen_url' => $googleFontSpecimenUrl,
                'google_font_role_label' => $roleLabel,
            ];
        }

        return $out;
    }
}
