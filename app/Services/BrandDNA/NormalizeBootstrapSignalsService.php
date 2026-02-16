<?php

namespace App\Services\BrandDNA;

/**
 * Phase 7: Normalize scraped brand signals.
 * - Keep only first H1
 * - Limit to top 3 H2 per page
 * - Remove utility nav items
 * - Limit colors to top 6, deduplicate
 * - Limit navigation labels to 8
 */
class NormalizeBootstrapSignalsService
{
    private const UTILITY_NAV_LABELS = [
        'cart', 'login', 'logout', 'sign in', 'sign up', 'support', 'privacy', 'terms',
        'contact us', 'help', 'faq', 'search', 'menu', 'account', 'checkout', 'my account',
    ];

    public function normalize(array $rawPayload): array
    {
        $homepage = $rawPayload['homepage'] ?? [];
        $additionalPages = $rawPayload['additional_pages'] ?? [];

        $allH1 = $this->collectH1($homepage, $additionalPages);
        $allH2 = $this->collectH2($homepage, $additionalPages);
        $allColors = $this->collectColors($homepage, $additionalPages);
        $allFonts = $this->collectFonts($homepage, $additionalPages);
        $navLinks = $this->filterNav($homepage['navigation']['links'] ?? []);

        return [
            'meta' => $homepage['meta'] ?? [],
            'branding' => $homepage['branding'] ?? [],
            'headlines' => [
                'h1' => array_slice($allH1, 0, 1),
                'h2' => array_slice(array_unique($allH2), 0, 3),
            ],
            'navigation' => [
                'links' => array_slice($navLinks, 0, 8),
            ],
            'colors_detected' => array_slice(array_unique($allColors), 0, 6),
            'font_families' => array_slice(array_values(array_unique($allFonts)), 0, 12),
        ];
    }

    protected function collectH1(array $homepage, array $additionalPages): array
    {
        $h1 = $homepage['headlines']['h1'] ?? [];
        foreach ($additionalPages as $page) {
            $pageH1 = $page['headlines']['h1'] ?? [];
            $h1 = array_merge($h1, array_slice($pageH1, 0, 1));
        }

        return array_values(array_filter(array_map('trim', $h1)));
    }

    protected function collectH2(array $homepage, array $additionalPages): array
    {
        $h2 = $homepage['headlines']['h2'] ?? [];
        foreach ($additionalPages as $page) {
            $pageH2 = $page['headlines']['h2'] ?? [];
            $h2 = array_merge($h2, array_slice($pageH2, 0, 3));
        }

        return array_values(array_filter(array_map('trim', $h2)));
    }

    protected function collectColors(array $homepage, array $additionalPages): array
    {
        $colors = $homepage['colors_detected'] ?? [];
        foreach ($additionalPages as $page) {
            $colors = array_merge($colors, $page['colors_detected'] ?? []);
        }

        return array_values(array_unique($colors));
    }

    protected function collectFonts(array $homepage, array $additionalPages): array
    {
        $fonts = $homepage['font_families'] ?? [];
        foreach ($additionalPages as $page) {
            $fonts = array_merge($fonts, $page['font_families'] ?? []);
        }

        return array_values(array_filter(array_map('trim', $fonts)));
    }

    protected function filterNav(array $links): array
    {
        return array_values(array_filter($links, function ($link) {
            $label = strtolower(trim($link['label'] ?? ''));
            foreach (self::UTILITY_NAV_LABELS as $utility) {
                if (str_contains($label, $utility)) {
                    return false;
                }
            }

            return $label !== '';
        }));
    }
}
