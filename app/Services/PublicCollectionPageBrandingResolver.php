<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Collection;
use Illuminate\Support\Facades\Route;

/**
 * Resolves branding for public collection landing pages.
 *
 * Pulls from Brand Settings > Public Pages (download_landing_settings):
 * - Brand Mark: logo_asset_id from library, fallback to brand identity (logo_id) if empty
 * - Accent Styling: color_role (primary, secondary, accent, custom)
 * - Background Visuals: background_asset_ids (randomized per visit)
 *
 * Background images are shown whenever background_asset_ids is configured;
 * accent and logo are always resolved from brand settings.
 */
class PublicCollectionPageBrandingResolver
{
    /**
     * Resolve branding options for a public collection page.
     *
     * @return array{logo_url: ?string, accent_color: string, primary_color: string, background_image_url: ?string, theme_dark: bool}
     */
    public function resolve(Brand $brand, Collection $collection): array
    {
        $settings = $brand->download_landing_settings ?? [];

        $colorRole = $settings['color_role'] ?? 'primary';
        $accentColor = $this->accentColorFromRole($brand, $colorRole);
        $primaryColor = $brand->primary_color ?? '#4F46E5';

        $logoUrl = null;
        $logoMode = $settings['logo_mode'] ?? null;
        $logoAssetId = $settings['logo_asset_id'] ?? null;
        if (! $logoMode) {
            $logoMode = $logoAssetId ? 'custom' : 'brand';
        }
        if ($logoMode !== 'none' && Route::has('public.collections.logo')) {
            $logoUrl = route('public.collections.logo', [
                'brand_slug' => $brand->slug,
                'collection_slug' => $collection->slug,
            ]);
        }

        $backgroundImageUrl = null;
        if (Route::has('public.collections.background')) {
            $backgroundIds = $settings['background_asset_ids'] ?? [];
            if (is_array($backgroundIds) && ! empty($backgroundIds)) {
                $backgroundImageUrl = route('public.collections.background', [
                    'brand_slug' => $brand->slug,
                    'collection_slug' => $collection->slug,
                ]);
            }
        }

        $themeDark = $this->isColorDark($primaryColor);

        return [
            'logo_url' => $logoUrl,
            'accent_color' => $accentColor,
            'primary_color' => is_string($primaryColor) ? $primaryColor : '#4F46E5',
            'background_image_url' => $backgroundImageUrl,
            'theme_dark' => $themeDark,
        ];
    }

    /**
     * Infer if a hex color is dark (for gradient/theme direction).
     * Dark colors → dark base + light gradients; light colors → light base + dark gradients.
     */
    protected function isColorDark(string $hex): bool
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if (strlen($hex) !== 6) {
            return true;
        }
        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;
        $luminance = 0.299 * $r + 0.587 * $g + 0.114 * $b;

        return $luminance < 0.5;
    }

    protected function accentColorFromRole(Brand $brand, string $role): string
    {
        $settings = $brand->download_landing_settings ?? [];
        $color = match ($role) {
            'secondary' => $brand->secondary_color ?? $brand->primary_color ?? '#64748b',
            'accent' => $brand->accent_color ?? $brand->primary_color ?? '#6366f1',
            'custom' => $settings['custom_color'] ?? $brand->primary_color ?? '#4F46E5',
            default => $brand->primary_color ?? '#4F46E5',
        };

        return is_string($color) ? $color : '#4F46E5';
    }
}
