<?php

namespace App\Services\BrandDNA;

/**
 * Website crawler for brand research. Stub implementation — returns placeholder data.
 * Replace with real DOM parsing when crawler is implemented.
 *
 * Crawl → Structured Snapshot flow: this service returns raw crawl data;
 * RunBrandResearchJob builds the structured snapshot from it.
 */
class BrandWebsiteCrawlerService
{
    /**
     * Crawl a URL and return structured data for snapshot building.
     * Stub: returns empty/placeholder. Real implementation would fetch HTML, parse DOM.
     *
     * @return array{logo_url: ?string, primary_colors: string[], detected_fonts: string[], hero_headlines: string[], brand_bio: ?string}
     */
    public function crawl(string $url): array
    {
        // Stub: simulate crawl delay in non-testing env
        if (! app()->environment('testing')) {
            sleep(2);
        }

        return [
            'logo_url' => null,
            'primary_colors' => [],
            'detected_fonts' => [],
            'hero_headlines' => [],
            'brand_bio' => null,
        ];
    }
}
