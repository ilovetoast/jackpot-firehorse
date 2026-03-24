<?php

namespace App\Services\BrandGateway;

use App\Models\Brand;
use App\Models\Tenant;

class BrandThemeBuilder
{
    private const DEFAULT_PRIMARY = '#6366f1';
    private const DEFAULT_SECONDARY = '#8b5cf6';
    private const DEFAULT_ACCENT = '#06b6d4';

    /**
     * Build a UI-ready theme object from the resolved tenant + brand.
     *
     * Priority cascade (portal always wins):
     *   portal_settings override → brand model/columns → tenant default brand → Jackpot defaults
     *
     * Tenants have no logo or colors — branding always comes from a Brand.
     * When only a tenant is available, we pull from its default brand.
     * When nothing is available, we return Jackpot defaults.
     */
    /**
     * @param  bool  $guestSignedLogos  Use signed CDN URLs for logos (gateway, public portal, forgot password). Guests have no tenant CloudFront cookies.
     */
    public function build(?Tenant $tenant, ?Brand $brand, bool $guestSignedLogos = false): array
    {
        $effectiveBrand = $brand ?? $this->resolveEffectiveBrand($tenant);

        $mode = $this->resolveMode($tenant, $brand);
        $colors = $this->resolveColors($effectiveBrand);
        $logo = $this->resolveLogo($effectiveBrand, $guestSignedLogos);
        $logoDark = $this->resolveLogoDark($effectiveBrand, $guestSignedLogos);
        $name = $this->resolveName($tenant, $brand);
        $tagline = $this->resolveTagline($effectiveBrand);

        return [
            'mode' => $mode,
            'logo' => $logo,
            'logo_dark' => $logoDark,
            'name' => $name,
            'tagline' => $tagline,
            'colors' => $colors,
            'background' => $this->resolveBackground($colors),
            'portal' => $this->resolvePortalOverrides($effectiveBrand),
            'presentation_style' => $this->resolvePresentationStyle($effectiveBrand),
        ];
    }

    /**
     * Build theme from the serialized gateway context array (tenant/brand ids).
     */
    public function buildFromGatewayContext(array $context): array
    {
        $tenant = isset($context['tenant']['id'])
            ? Tenant::with('defaultBrand')->find($context['tenant']['id'])
            : null;

        $brand = isset($context['brand']['id'])
            ? Brand::find($context['brand']['id'])
            : null;

        return $this->build($tenant, $brand, true);
    }

    private function resolveMode(?Tenant $tenant, ?Brand $brand): string
    {
        if ($brand) {
            return 'brand';
        }
        if ($tenant) {
            return 'tenant';
        }

        return 'default';
    }

    private function resolveEffectiveBrand(?Tenant $tenant): ?Brand
    {
        if (! $tenant) {
            return null;
        }

        return $tenant->relationLoaded('defaultBrand')
            ? $tenant->defaultBrand
            : $tenant->defaultBrand()->first();
    }

    private function resolveName(?Tenant $tenant, ?Brand $brand): string
    {
        if ($brand) {
            return $brand->name;
        }
        if ($tenant) {
            return $tenant->name;
        }

        return 'Jackpot';
    }

    private function resolveLogo(?Brand $brand, bool $guestSignedLogos = false): ?string
    {
        if (! $brand) {
            return null;
        }

        try {
            if ($guestSignedLogos) {
                return $brand->logoUrlForGuest(false);
            }

            $logo = $brand->logo_path;

            return ($logo !== null && $logo !== '') ? $logo : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveLogoDark(?Brand $brand, bool $guestSignedLogos = false): ?string
    {
        if (! $brand) {
            return null;
        }

        try {
            if ($guestSignedLogos) {
                return $brand->logoUrlForGuest(true);
            }

            $logoDark = $brand->logo_dark_path;

            return ($logoDark !== null && $logoDark !== '') ? $logoDark : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Tagline priority: portal_settings.entry.tagline_override > Brand DNA tagline > null
     */
    private function resolveTagline(?Brand $brand): ?string
    {
        if (! $brand) {
            return null;
        }

        $portalTagline = $brand->getPortalSetting('entry.tagline_override');
        if ($portalTagline !== null && $portalTagline !== '') {
            return $portalTagline;
        }

        try {
            $brandModel = $brand->brandModel;
            if (! $brandModel || ! $brandModel->active_version_id) {
                return null;
            }

            $activeVersion = $brandModel->activeVersion;
            $payload = $activeVersion?->model_payload ?? [];

            return $payload['identity']['tagline'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveColors(?Brand $brand): array
    {
        $primary = $this->fallbackColor($brand?->primary_color, self::DEFAULT_PRIMARY);
        $secondary = $this->fallbackColor(
            $brand?->secondary_color,
            $this->lighten($primary, 0.2)
        );
        $accent = $this->fallbackColor(
            $brand?->accent_color,
            $this->saturate($primary, 0.3)
        );

        return [
            'primary' => $primary,
            'secondary' => $secondary,
            'accent' => $accent,
        ];
    }

    private function resolveBackground(array $colors): array
    {
        $primary = $colors['primary'];
        $secondary = $colors['secondary'];

        $value = sprintf(
            'radial-gradient(circle at 20%% 20%%, %s33, transparent),'
            . ' radial-gradient(circle at 80%% 80%%, %s33, transparent),'
            . ' #0B0B0D',
            $primary,
            $secondary
        );

        return [
            'type' => 'gradient',
            'value' => $value,
            'overlay' => 'dark',
        ];
    }

    /**
     * Resolve portal-specific overrides from brand.portal_settings.
     *
     * Cascade: portal_settings.{key} > brand column/model > fallback default.
     * This ensures portal always "wins" for any field it defines.
     */
    private function resolvePortalOverrides(?Brand $brand): array
    {
        if (! $brand) {
            return $this->defaultPortalSettings();
        }

        $ps = $brand->portal_settings ?? [];

        return [
            'entry' => [
                'style' => data_get($ps, 'entry.style', 'cinematic'),
                'auto_enter' => (bool) data_get($ps, 'entry.auto_enter', true),
                'default_destination' => data_get($ps, 'entry.default_destination', 'assets'),
                'primary_button' => data_get($ps, 'entry.primary_button', 'assets'),
                'secondary_button' => data_get($ps, 'entry.secondary_button', 'guidelines'),
                'tagline_override' => data_get($ps, 'entry.tagline_override'),
            ],
            'public' => [
                'enabled' => (bool) data_get($ps, 'public.enabled', false),
                'visibility' => data_get($ps, 'public.visibility', 'private'),
                'indexable' => (bool) data_get($ps, 'public.indexable', false),
            ],
            'sharing' => [
                'external_collections' => (bool) data_get($ps, 'sharing.external_collections', false),
                'expiring_links' => (bool) data_get($ps, 'sharing.expiring_links', false),
                'watermark_branding' => (bool) data_get($ps, 'sharing.watermark_branding', false),
            ],
            'invite' => [
                'headline' => data_get($ps, 'invite.headline'),
                'subtext' => data_get($ps, 'invite.subtext'),
                'background_style' => data_get($ps, 'invite.background_style', 'brand'),
                'cta_label' => data_get($ps, 'invite.cta_label'),
            ],
            'agency_template' => [
                'enabled' => (bool) data_get($ps, 'agency_template.enabled', false),
                'template_id' => data_get($ps, 'agency_template.template_id'),
                'locked_fields' => data_get($ps, 'agency_template.locked_fields', []),
            ],
        ];
    }

    private function defaultPortalSettings(): array
    {
        return [
            'entry' => [
                'style' => 'cinematic',
                'auto_enter' => true,
                'default_destination' => 'assets',
                'primary_button' => 'assets',
                'secondary_button' => 'guidelines',
                'tagline_override' => null,
            ],
            'public' => [
                'enabled' => false,
                'visibility' => 'private',
                'indexable' => false,
            ],
            'sharing' => [
                'external_collections' => false,
                'expiring_links' => false,
                'watermark_branding' => false,
            ],
            'invite' => [
                'headline' => null,
                'subtext' => null,
                'background_style' => 'brand',
                'cta_label' => null,
            ],
            'agency_template' => [
                'enabled' => false,
                'template_id' => null,
                'locked_fields' => [],
            ],
        ];
    }

    private function fallbackColor(?string $value, string $fallback): string
    {
        return ($value !== null && $value !== '') ? $value : $fallback;
    }

    private function lighten(string $hex, float $amount = 0.2): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) {
            return '#' . $hex;
        }

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        $r = (int) min(255, $r + (255 - $r) * $amount);
        $g = (int) min(255, $g + (255 - $g) * $amount);
        $b = (int) min(255, $b + (255 - $b) * $amount);

        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    private function saturate(string $hex, float $amount = 0.3): string
    {
        return $this->lighten($hex, $amount * 0.5);
    }

    private function resolvePresentationStyle(?Brand $brand): string
    {
        if (! $brand) {
            return 'clean';
        }

        $activeVersion = $brand->brandModel?->activeVersion;
        $payload = $activeVersion?->model_payload ?? [];
        if ($payload instanceof \Illuminate\Support\Collection) {
            $payload = $payload->toArray();
        }

        return data_get($payload, 'presentation.style', 'clean');
    }
}
