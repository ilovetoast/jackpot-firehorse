<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Brand;
use App\Models\Download;
use Illuminate\Support\Facades\Route;

/**
 * Single source of truth for public download page branding.
 *
 * Resolves show_landing_layout and branding_options for ALL public-facing download states:
 * active download, password prompt, 403 (unauthorized/bad password), 404 (not found),
 * expired, revoked, failed, processing, and any future terminal state.
 *
 * Why error pages must share this branding logic: If 404, 403, expired, or revoked were rendered
 * with separate logic or unbranded layouts, users would see inconsistent presentation when a
 * branded download link fails. Every state must use resolve() so branding is consistent—brand
 * template when landing required + single-brand + branding enabled; default Jackpot otherwise.
 * No silent fallbacks to unbranded layouts. Intentional design, not a shortcut.
 *
 * IMPORTANT: Every code path that renders Inertia('Downloads/Public', ...) MUST call
 * resolve($download, $message) and pass the returned show_landing_layout and branding_options
 * into the page props. Do not duplicate branding logic or silently fall back to unbranded layouts.
 */
class DownloadPublicPageBrandingResolver
{
    /**
     * Resolve branding for the public download page.
     *
     * @param  Download|null  $download  The download (null for 404 / not found)
     * @param  string  $message  Optional message (e.g. for 404, expired, revoked)
     * @return array{show_landing_layout: bool, branding_options: array}
     */
    public function resolve(?Download $download, string $message = ''): array
    {
        if ($download === null) {
            return $this->defaultJackpotBranding($message);
        }

        $templateBrand = $download->getLandingPageTemplateBrand();

        if ($templateBrand !== null) {
            return [
                'show_landing_layout' => true,
                'branding_options' => $this->buildBrandingFromTemplateBrand($download, $templateBrand),
            ];
        }

        // Legacy: download has no template brand but may have stored branding_options
        $legacy = $download->branding_options ?? [];
        if ($this->hasMeaningfulLegacyBranding($legacy)) {
            return [
                'show_landing_layout' => true,
                'branding_options' => $this->normalizeLegacyBranding($legacy, $download),
            ];
        }

        return $this->defaultJackpotBranding($message);
    }

    /**
     * Default Jackpot branding (no brand template). Used for 404 or when no brand template applies.
     */
    protected function defaultJackpotBranding(string $message = ''): array
    {
        $appName = config('app.name', 'Jackpot');

        return [
            'show_landing_layout' => false,
            'branding_options' => [
                'logo_url' => null,
                'accent_color' => '#4F46E5',
                'overlay_color' => '#4F46E5',
                'headline' => $appName,
                'subtext' => $message ?: 'Download',
                'background_image_url' => null,
            ],
        ];
    }

    /**
     * Build branding_options from template brand and download landing_copy.
     */
    protected function buildBrandingFromTemplateBrand(Download $download, Brand $brand): array
    {
        $settings = $brand->download_landing_settings ?? [];
        $colorRole = $settings['color_role'] ?? 'primary';
        $accentColor = $this->accentColorFromRole($brand, $colorRole);
        $overlayColor = $accentColor;

        $logoUrl = null;
        $logoAssetId = $settings['logo_asset_id'] ?? null;
        if ($logoAssetId && Route::has('assets.thumbnail.final')) {
            $logoAsset = Asset::where('id', $logoAssetId)->where('brand_id', $brand->id)->first();
            if ($logoAsset) {
                $logoUrl = route('assets.thumbnail.final', ['asset' => $logoAsset->id, 'style' => 'medium']);
            }
        }

        $headline = $download->landing_copy['headline'] ?? $settings['default_headline'] ?? $brand->name ?? '';
        $subtext = $download->landing_copy['subtext'] ?? $settings['default_subtext'] ?? '';

        $backgroundImageUrl = null;
        $backgroundIds = $settings['background_asset_ids'] ?? [];
        if (is_array($backgroundIds) && ! empty($backgroundIds) && Route::has('downloads.public.background')) {
            $backgroundImageUrl = route('downloads.public.background', ['download' => $download->id]);
        }

        return [
            'logo_url' => $logoUrl,
            'accent_color' => $accentColor,
            'overlay_color' => $overlayColor,
            'headline' => $headline,
            'subtext' => $subtext,
            'background_image_url' => $backgroundImageUrl,
        ];
    }

    protected function accentColorFromRole(Brand $brand, string $role): string
    {
        $color = match ($role) {
            'secondary' => $brand->secondary_color ?? $brand->primary_color ?? '#64748b',
            'accent' => $brand->accent_color ?? $brand->primary_color ?? '#6366f1',
            default => $brand->primary_color ?? '#4F46E5',
        };

        return is_string($color) ? $color : '#4F46E5';
    }

    protected function hasMeaningfulLegacyBranding(array $legacy): bool
    {
        return ! empty($legacy['logo_url'])
            || ! empty($legacy['headline'])
            || ! empty($legacy['subtext'])
            || ! empty($legacy['accent_color']);
    }

    /**
     * Normalize legacy branding_options and add background URL when download has template brand context.
     */
    protected function normalizeLegacyBranding(array $legacy, Download $download): array
    {
        $out = [
            'logo_url' => $legacy['logo_url'] ?? null,
            'accent_color' => $legacy['accent_color'] ?? '#4F46E5',
            'overlay_color' => $legacy['overlay_color'] ?? $legacy['accent_color'] ?? '#4F46E5',
            'headline' => $legacy['headline'] ?? '',
            'subtext' => $legacy['subtext'] ?? '',
            'background_image_url' => $legacy['background_image_url'] ?? null,
        ];

        if ($out['background_image_url'] === null && Route::has('downloads.public.background')) {
            $brand = $download->getLandingPageTemplateBrand();
            if ($brand !== null) {
                $settings = $brand->download_landing_settings ?? [];
                $backgroundIds = $settings['background_asset_ids'] ?? [];
                if (is_array($backgroundIds) && ! empty($backgroundIds)) {
                    $out['background_image_url'] = route('downloads.public.background', ['download' => $download->id]);
                }
            }
        }

        return $out;
    }
}
