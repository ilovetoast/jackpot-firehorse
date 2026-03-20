<?php

namespace App\Services\BrandDNA;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\Brand;
use App\Models\BrandModelVersion;
use App\Models\BrandModelVersionAsset;
use App\Services\AssetPathGenerator;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Website crawler for brand research.
 * Fetches HTML, extracts logo (including inline SVGs), colors, fonts, headlines, and brand bio.
 * Reuses logic patterns from ScrapesBootstrapHtml trait but adapted for the Brand DNA pipeline.
 */
class BrandWebsiteCrawlerService
{
    /** Max distinct logo URLs kept on the crawl result (header + footer + heuristics). */
    private const MAX_LOGO_CANDIDATES = 25;

    /** How many non-primary logo images to download into the draft as library assets (pipeline). */
    private const MAX_CRAWLED_LOGO_VARIANT_DOWNLOADS = 12;

    /** Minimum extraction score to auto-download a variant (filters low-value og/twitter art). */
    private const MIN_SCORE_FOR_VARIANT_DOWNLOAD = 52;

    /**
     * Crawl a URL and return structured data for snapshot building.
     *
     * @return array{logo_url: ?string, logo_svg: ?string, logo_candidates: array, logo_candidate_entries: array<int, array{url: string, score: int, source: string}>, primary_colors: string[], detected_fonts: string[], hero_headlines: string[], brand_bio: ?string, favicon: ?string}
     */
    public function crawl(string $url): array
    {
        $url = $this->normalizeUrl($url);

        $result = [
            'logo_url' => null,
            'logo_svg' => null,
            'logo_candidates' => [],
            'logo_candidate_entries' => [],
            'primary_colors' => [],
            'detected_fonts' => [],
            'hero_headlines' => [],
            'brand_bio' => null,
            'favicon' => null,
        ];

        try {
            $html = $this->fetchHtml($url);
        } catch (\Throwable $e) {
            Log::channel('pipeline')->warning('[BrandWebsiteCrawlerService] Fetch failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return $result;
        }

        if (empty(trim($html))) {
            return $result;
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $baseUrl = $this->getBaseUrl($url);

        $branding = $this->extractBranding($dom, $xpath, $baseUrl, $html);
        $result['logo_url'] = $branding['logo_url'];
        $result['logo_svg'] = $branding['logo_svg'];
        $result['logo_candidates'] = $branding['logo_candidates'];
        $result['logo_candidate_entries'] = $branding['logo_candidate_entries'] ?? [];
        $result['favicon'] = $branding['favicon'];

        $result['primary_colors'] = $this->extractColors($html);
        $result['detected_fonts'] = $this->extractFonts($html, $dom, $xpath);
        $result['hero_headlines'] = $this->extractHeadlines($xpath);
        $result['brand_bio'] = $this->extractBrandBio($dom, $xpath);

        Log::channel('pipeline')->info('[BrandWebsiteCrawlerService] Crawl complete', [
            'url' => $url,
            'logo_url' => $result['logo_url'],
            'has_svg_logo' => ! empty($result['logo_svg']),
            'logo_candidate_count' => count($result['logo_candidates']),
            'colors_count' => count($result['primary_colors']),
            'fonts_count' => count($result['detected_fonts']),
        ]);

        return $result;
    }

    /**
     * Download a logo (URL or SVG code) and create a builder-staged asset.
     * Returns the Asset or null if download/creation fails.
     */
    public function downloadLogoAsAsset(array $crawlResult, Brand $brand, BrandModelVersion $draft): ?Asset
    {
        $svgCode = $crawlResult['logo_svg'] ?? null;
        $logoUrl = $crawlResult['logo_url'] ?? null;

        if ($svgCode) {
            return $this->saveSvgLogoAsAsset($svgCode, $brand, $draft);
        }

        if ($logoUrl) {
            return $this->downloadImageLogoAsAsset($logoUrl, $brand, $draft, 'logo_reference', 'Website Logo', 'logo');
        }

        return null;
    }

    /**
     * Download additional raster logo candidates (e.g. footer variants) as staged assets linked to the draft.
     * Skips the primary raster URL and low-confidence sources. Does not download SVG bodies here (primary SVG is handled in downloadLogoAsAsset).
     *
     * @return int Number of variants successfully created and attached
     */
    public function downloadCrawledLogoVariants(array $crawlResult, Brand $brand, BrandModelVersion $draft): int
    {
        $entries = $crawlResult['logo_candidate_entries'] ?? [];
        if ($entries === []) {
            return 0;
        }

        $primaryUrl = $crawlResult['logo_url'] ?? null;
        $primaryKey = $primaryUrl ? $this->urlIdentityKey($primaryUrl) : null;

        $saved = 0;
        $attempt = 0;

        foreach ($entries as $entry) {
            if (($entry['score'] ?? 0) < self::MIN_SCORE_FOR_VARIANT_DOWNLOAD) {
                continue;
            }
            $url = $entry['url'] ?? '';
            if ($url === '') {
                continue;
            }
            if ($primaryKey !== null && $this->urlIdentityKey($url) === $primaryKey) {
                continue;
            }
            $source = strtolower((string) ($entry['source'] ?? ''));
            if (str_contains($source, 'og_twitter')) {
                continue;
            }

            $attempt++;
            if ($attempt > self::MAX_CRAWLED_LOGO_VARIANT_DOWNLOADS) {
                break;
            }

            $label = str_replace([' ', '/'], '-', (string) ($entry['source'] ?? 'variant'));
            $label = preg_replace('/[^a-z0-9._-]+/i', '', $label) ?: 'variant';

            $asset = $this->downloadImageLogoAsAsset(
                $url,
                $brand,
                $draft,
                'crawled_logo_variant',
                'Website logo ('.$label.' '.$attempt.')',
                'logo-crawl-'.$attempt.'-'.$label
            );

            if ($asset) {
                $saved++;
                Log::channel('pipeline')->info('[BrandWebsiteCrawlerService] Crawled logo variant saved', [
                    'asset_id' => $asset->id,
                    'url' => $url,
                    'source' => $entry['source'] ?? null,
                ]);
            }
        }

        return $saved;
    }

    /**
     * Same host + path identity (ignores query string width/size params).
     */
    protected function urlIdentityKey(string $url): string
    {
        $normalized = $this->normalizeHttpUrl($url);
        $p = parse_url($normalized);

        return strtolower((string) ($p['host'] ?? '')).($p['path'] ?? '');
    }

    protected function saveSvgLogoAsAsset(
        string $svgCode,
        Brand $brand,
        BrandModelVersion $draft,
        string $pivotContext = 'logo_reference',
        ?string $title = null,
        ?string $filenameBase = null,
    ): ?Asset {
        try {
            $svgCode = trim($svgCode);
            if (! str_starts_with($svgCode, '<svg') && ! str_starts_with($svgCode, '<?xml')) {
                return null;
            }

            $tenant = $brand->tenant;
            if (! $tenant?->uuid) {
                return null;
            }

            $stem = $filenameBase ? $this->sanitizeFilenameStem($filenameBase) : 'logo';
            $asset = $this->createProgrammaticAsset($brand, [
                'title' => $title ?? 'Website Logo (SVG)',
                'original_filename' => $stem.'.svg',
                'mime_type' => 'image/svg+xml',
                'size_bytes' => strlen($svgCode),
                'builder_context' => $pivotContext,
                'source' => $pivotContext === 'crawled_logo_variant' ? 'website_crawl_variant' : 'website_crawl',
            ]);

            $pathGenerator = app(AssetPathGenerator::class);
            $path = $pathGenerator->generateOriginalPath($tenant, $asset, 1, 'svg');

            Storage::disk('s3')->put($path, $svgCode, 'private');

            $this->createVersionAndFinalize($asset, $path, strlen($svgCode), 'image/svg+xml');
            $this->attachAssetToDraft($draft, $asset, $pivotContext);

            Log::channel('pipeline')->info('[BrandWebsiteCrawlerService] SVG logo saved as asset', [
                'asset_id' => $asset->id,
                'brand_id' => $brand->id,
                'path' => $path,
            ]);

            return $asset;
        } catch (\Throwable $e) {
            Log::channel('pipeline')->warning('[BrandWebsiteCrawlerService] SVG logo save failed', [
                'error' => $e->getMessage(),
                'brand_id' => $brand->id,
            ]);

            return null;
        }
    }

    protected function downloadImageLogoAsAsset(
        string $logoUrl,
        Brand $brand,
        BrandModelVersion $draft,
        string $pivotContext = 'logo_reference',
        ?string $title = null,
        ?string $filenameStem = null,
    ): ?Asset {
        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
                ])
                ->get($logoUrl);

            if (! $response->successful()) {
                return null;
            }

            $body = $response->body();
            $contentType = $response->header('Content-Type') ?? 'image/png';
            $mimeType = explode(';', $contentType)[0];
            $stem = $filenameStem ? $this->sanitizeFilenameStem($filenameStem) : 'logo';

            $isSvg = str_contains($mimeType, 'svg') || str_starts_with(trim($body), '<svg') || str_starts_with(trim($body), '<?xml');
            if ($isSvg) {
                return $this->saveSvgLogoAsAsset(
                    $body,
                    $brand,
                    $draft,
                    $pivotContext,
                    $title ?? 'Website Logo (SVG)',
                    $stem
                );
            }

            $ext = match (true) {
                str_contains($mimeType, 'png') => 'png',
                str_contains($mimeType, 'jpeg'), str_contains($mimeType, 'jpg') => 'jpg',
                str_contains($mimeType, 'webp') => 'webp',
                str_contains($mimeType, 'gif') => 'gif',
                default => 'png',
            };

            $tenant = $brand->tenant;
            if (! $tenant?->uuid) {
                return null;
            }
            $asset = $this->createProgrammaticAsset($brand, [
                'title' => $title ?? 'Website Logo',
                'original_filename' => $stem.'.'.$ext,
                'mime_type' => $mimeType,
                'size_bytes' => strlen($body),
                'builder_context' => $pivotContext,
                'source' => $pivotContext === 'crawled_logo_variant' ? 'website_crawl_variant' : 'website_crawl',
            ]);

            $pathGenerator = app(AssetPathGenerator::class);
            $path = $pathGenerator->generateOriginalPath($tenant, $asset, 1, $ext);

            Storage::disk('s3')->put($path, $body, 'private');

            $this->createVersionAndFinalize($asset, $path, strlen($body), $mimeType);
            $this->attachAssetToDraft($draft, $asset, $pivotContext);
            $this->dispatchProcessing($asset);

            return $asset;
        } catch (\Throwable $e) {
            Log::channel('pipeline')->warning('[BrandWebsiteCrawlerService] Image logo download failed', [
                'url' => $logoUrl,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function sanitizeFilenameStem(string $stem): string
    {
        $stem = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $stem) ?? 'logo';
        $stem = trim($stem, '-');

        return $stem !== '' ? substr($stem, 0, 80) : 'logo';
    }

    protected function attachAssetToDraft(BrandModelVersion $draft, Asset $asset, string $context = 'logo_reference'): void
    {
        $exists = BrandModelVersionAsset::where('brand_model_version_id', $draft->id)
            ->where('asset_id', $asset->id)
            ->where('builder_context', $context)
            ->exists();

        if (! $exists) {
            BrandModelVersionAsset::create([
                'brand_model_version_id' => $draft->id,
                'asset_id' => $asset->id,
                'builder_context' => $context,
            ]);
        }
    }

    /**
     * Create an asset record with all required fields for programmatic (non-upload) creation.
     * Mirrors the UploadCompletionService contract minus upload_session_id/storage_bucket_id.
     */
    protected function createProgrammaticAsset(Brand $brand, array $fields): Asset
    {
        return Asset::create([
            'tenant_id' => $brand->tenant_id,
            'brand_id' => $brand->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::REFERENCE,
            'title' => $fields['title'],
            'original_filename' => $fields['original_filename'],
            'mime_type' => $fields['mime_type'],
            'size_bytes' => $fields['size_bytes'],
            'thumbnail_status' => ThumbnailStatus::PENDING,
            'intake_state' => 'staged',
            'builder_staged' => true,
            'builder_context' => $fields['builder_context'] ?? 'logo_reference',
            'source' => $fields['source'] ?? 'website_crawl',
        ]);
    }

    /**
     * Create an AssetVersion, then update the asset's storage_root_path to the canonical path.
     */
    protected function createVersionAndFinalize(Asset $asset, string $path, int $fileSize, string $mimeType): void
    {
        AssetVersion::create([
            'id' => (string) Str::uuid(),
            'asset_id' => $asset->id,
            'version_number' => 1,
            'file_path' => $path,
            'file_size' => $fileSize,
            'mime_type' => $mimeType,
            'is_current' => true,
            'pipeline_status' => 'pending',
        ]);

        $asset->update(['storage_root_path' => $path]);
    }

    /**
     * Dispatch the thumbnail/metadata processing pipeline for raster images.
     * SVGs can be served directly so this is only needed for PNG/JPG/WebP/GIF.
     */
    protected function dispatchProcessing(Asset $asset): void
    {
        try {
            $version = $asset->currentVersion;
            if ($version) {
                $version->update(['pipeline_status' => 'processing']);
                \App\Jobs\ProcessAssetJob::dispatch($version->id);
            }
        } catch (\Throwable $e) {
            Log::channel('pipeline')->warning('[BrandWebsiteCrawlerService] Processing dispatch failed', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ── HTML fetching ──────────────────────────────────────────────

    protected function fetchHtml(string $url): string
    {
        $response = Http::timeout(15)
            ->withHeaders([
                // Browser-like UA: some storefronts (Shopify, etc.) serve full header markup only to real browsers.
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
            ])
            ->withOptions(['allow_redirects' => true])
            ->get($url);

        $response->throw();

        return $response->body();
    }

    protected function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        return rtrim($url, '/');
    }

    protected function getBaseUrl(string $url): string
    {
        $parsed = parse_url($url);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';

        return $scheme.'://'.$host;
    }

    // ── Branding (logo + favicon) ─────────────────────────────────

    protected function extractBranding(DOMDocument $dom, DOMXPath $xpath, string $baseUrl, string $html): array
    {
        $favicon = '';
        $logoUrl = null;
        $logoSvg = null;

        /** @var list<array{url: string, score: int, source: string}> $scored */
        $scored = [];

        $push = function (string $url, int $score, string $source) use (&$scored, $baseUrl): void {
            $resolved = $this->normalizeHttpUrl($this->resolveUrl(trim($url), $baseUrl));
            if ($resolved === '' || str_starts_with($resolved, 'data:')) {
                return;
            }
            $scored[] = ['url' => $resolved, 'score' => $score, 'source' => $source];
        };

        // Favicon
        $links = $xpath->query('//link[@rel="icon" or @rel="shortcut icon"]/@href');
        if ($links->length > 0) {
            $favicon = $this->normalizeHttpUrl($this->resolveUrl(trim($links->item(0)->value ?? ''), $baseUrl));
        }

        // 0. JSON-LD Organization / WebSite logo (Shopify & many CMSs)
        foreach ($this->extractLogoUrlsFromJsonLd($html) as $u) {
            $push($u, 100, 'json_ld');
        }

        // 0b. Microdata itemprop="logo"
        foreach ($xpath->query('//*[@itemprop="logo"]') as $el) {
            if (! $el instanceof \DOMElement) {
                continue;
            }
            if (strtolower($el->nodeName) === 'img') {
                $u = $this->extractBestImgCandidateUrl($el, $baseUrl);
                if ($u !== '') {
                    $push($u, 99, 'microdata_img');
                }
            } elseif (strtolower($el->nodeName) === 'link') {
                $href = $el->getAttribute('href');
                if ($href !== '') {
                    $push($href, 99, 'microdata_link');
                }
            } else {
                foreach ($xpath->query('.//img', $el) as $nestedImg) {
                    if ($nestedImg instanceof \DOMElement) {
                        $u = $this->extractBestImgCandidateUrl($nestedImg, $baseUrl);
                        if ($u !== '') {
                            $push($u, 98, 'microdata_nested');
                        }
                        break;
                    }
                }
                $style = $el->getAttribute('style');
                foreach ($this->extractUrlsFromCssBackground($style) as $u) {
                    $push($u, 95, 'microdata_bg');
                }
            }
        }

        // 1. Inline SVG logos: look for <svg> inside elements with logo-related class/id/aria-label
        $logoContainers = $xpath->query(
            '//*[contains(translate(@class,"LOGO","logo"),"logo") or contains(translate(@id,"LOGO","logo"),"logo") or contains(translate(@aria-label,"LOGO","logo"),"logo")]'
        );

        foreach ($logoContainers as $container) {
            $svgs = $xpath->query('.//svg', $container);
            if ($svgs->length > 0) {
                $svgNode = $svgs->item(0);
                $svgHtml = $dom->saveHTML($svgNode);
                if ($svgHtml && strlen($svgHtml) > 20 && strlen($svgHtml) < 100000) {
                    $logoSvg = $svgHtml;
                    break;
                }
            }
        }

        $host = (string) (parse_url($baseUrl, PHP_URL_HOST) ?? '');
        $homeLinkQuery = $host !== ''
            ? '//a[@href="/" or @href="/index" or @href="/index.html" or @href="'.$baseUrl.'" or @href="'.$baseUrl.'/" or starts-with(@href, "https://'.$host.'") or starts-with(@href, "http://'.$host.'") or starts-with(@href, "//'.$host.'")]'
            : '//a[@href="/" or @href="/index" or @href="/index.html" or @href="'.$baseUrl.'" or @href="'.$baseUrl.'/"]';
        // $homeLinkQuery is `//a[...]` — reuse predicate for header/nav scoped queries
        $homeLinkPredicate = substr($homeLinkQuery, 3);

        // 2a. Images inside header/nav home links (top-left pattern)
        foreach (['//header//a'.$homeLinkPredicate, '//nav//a'.$homeLinkPredicate, '//*[@role="banner"]//a'.$homeLinkPredicate] as $hq) {
            foreach ($xpath->query($hq) as $link) {
                if (! $link instanceof \DOMElement) {
                    continue;
                }
                foreach ($xpath->query('.//img', $link) as $img) {
                    if ($img instanceof \DOMElement) {
                        $u = $this->extractBestImgCandidateUrl($img, $baseUrl);
                        if ($u !== '') {
                            $push($u, 92, 'header_home_img');
                        }
                    }
                }
                foreach ($this->extractUrlsFromCssBackground($link->getAttribute('style')) as $u) {
                    $push($u, 88, 'header_home_bg');
                }
            }
        }

        // 2b. SVG in <a> linking to homepage
        if (! $logoSvg) {
            foreach ($xpath->query($homeLinkQuery) as $link) {
                $svgs = $xpath->query('.//svg', $link);
                if ($svgs->length > 0) {
                    $svgNode = $svgs->item(0);
                    $svgHtml = $dom->saveHTML($svgNode);
                    if ($svgHtml && strlen($svgHtml) > 20 && strlen($svgHtml) < 100000) {
                        $logoSvg = $svgHtml;
                        break;
                    }
                }
                foreach ($xpath->query('.//img', $link) as $img) {
                    if ($img instanceof \DOMElement) {
                        $u = $this->extractBestImgCandidateUrl($img, $baseUrl);
                        if ($u !== '') {
                            $push($u, 85, 'home_link_img');
                        }
                    }
                }
            }
        }

        // 2c. CSS background-image on logo-like blocks in header (common when "logo" is a div)
        foreach ($xpath->query(
            '//header//*[contains(translate(@class,"LOGO","logo"),"logo") or contains(translate(@id,"LOGO","logo"),"logo") or contains(translate(@class,"BRAND","brand"),"brand")][@style]'
        ) as $el) {
            if ($el instanceof \DOMElement) {
                foreach ($this->extractUrlsFromCssBackground($el->getAttribute('style')) as $u) {
                    $push($u, 87, 'header_logo_bg');
                }
            }
        }

        // 3. Header SVGs (often the logo is the first SVG in the header/nav)
        if (! $logoSvg) {
            $headerSvgs = $xpath->query('//header//svg | //nav//svg');
            if ($headerSvgs->length > 0) {
                $svgNode = $headerSvgs->item(0);
                $parent = $svgNode->parentNode;
                $parentClass = strtolower($parent?->getAttribute('class') ?? '');
                $parentTag = strtolower($parent?->nodeName ?? '');
                if ($parentTag === 'a' || str_contains($parentClass, 'logo') || str_contains($parentClass, 'brand')) {
                    $svgHtml = $dom->saveHTML($svgNode);
                    if ($svgHtml && strlen($svgHtml) > 20 && strlen($svgHtml) < 100000) {
                        $logoSvg = $svgHtml;
                    }
                }
            }
        }

        // 4. Header / banner images first (DOM order in chrome is usually top-left brand)
        foreach ($xpath->query('//header//img | //nav//img | //*[@role="banner"]//img') as $img) {
            if (! $img instanceof \DOMElement) {
                continue;
            }
            $class = strtolower($img->getAttribute('class') ?? '');
            $id = strtolower($img->getAttribute('id') ?? '');
            $u = $this->extractBestImgCandidateUrl($img, $baseUrl);
            if ($u === '') {
                continue;
            }
            $score = 72;
            if ($this->isLogoLikeTokenString($class.' '.$id)) {
                $score = 94;
            } elseif (str_contains($class, 'heading') || str_contains($class, 'navbar') || str_contains($class, 'site-header')) {
                $score = 88;
            }
            $push($u, $score, 'header_img');
        }

        // 4b. Footer & site-footer — alternate marks (stacked wordmark, footer brand, etc.)
        foreach (['//footer//a'.$homeLinkPredicate, '//nav[@aria-label="Footer"]//a'.$homeLinkPredicate] as $hq) {
            foreach ($xpath->query($hq) as $link) {
                if (! $link instanceof \DOMElement) {
                    continue;
                }
                foreach ($xpath->query('.//img', $link) as $img) {
                    if ($img instanceof \DOMElement) {
                        $u = $this->extractBestImgCandidateUrl($img, $baseUrl);
                        if ($u !== '') {
                            $push($u, 78, 'footer_home_img');
                        }
                    }
                }
                foreach ($this->extractUrlsFromCssBackground($link->getAttribute('style')) as $u) {
                    $push($u, 74, 'footer_home_bg');
                }
            }
        }
        foreach ($xpath->query('//footer//img | //*[@role="contentinfo"]//img | //*[contains(translate(@class,"FOOTER","footer"),"site-footer")]//img') as $img) {
            if (! $img instanceof \DOMElement) {
                continue;
            }
            $class = strtolower($img->getAttribute('class') ?? '');
            $id = strtolower($img->getAttribute('id') ?? '');
            $u = $this->extractBestImgCandidateUrl($img, $baseUrl);
            if ($u === '') {
                continue;
            }
            $score = 66;
            if ($this->isLogoLikeTokenString($class.' '.$id)) {
                $score = 82;
            } elseif (str_contains($class, 'footer') || str_contains($id, 'footer')) {
                $score = 72;
            }
            $push($u, $score, 'footer_img');
        }
        foreach ($xpath->query(
            '//footer//*[contains(translate(@class,"LOGO","logo"),"logo") or contains(translate(@id,"LOGO","logo"),"logo") or contains(translate(@class,"BRAND","brand"),"brand")][@style]'
        ) as $el) {
            if ($el instanceof \DOMElement) {
                foreach ($this->extractUrlsFromCssBackground($el->getAttribute('style')) as $u) {
                    $push($u, 73, 'footer_logo_bg');
                }
            }
        }

        // 5. Any <img> with logo / brand heuristics in attributes (full page; no early break)
        foreach ($xpath->query('//img') as $img) {
            if (! $img instanceof \DOMElement) {
                continue;
            }
            $src = strtolower($img->getAttribute('src') ?? '');
            $class = strtolower($img->getAttribute('class') ?? '');
            $id = strtolower($img->getAttribute('id') ?? '');
            $alt = strtolower($img->getAttribute('alt') ?? '');
            $haystack = $class.' '.$id.' '.$src.' '.$alt;
            if (
                str_contains($haystack, 'logo')
                || str_contains($haystack, 'brand')
                || str_contains($haystack, 'site-title')
                || str_contains($haystack, 'custom-logo')
            ) {
                $u = $this->extractBestImgCandidateUrl($img, $baseUrl);
                if ($u !== '') {
                    $push($u, 78, 'img_heuristic');
                }
            }
        }

        // 6. Apple touch icon (often a square mark; better than nothing)
        $apple = $xpath->query('//link[translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="apple-touch-icon"]/@href');
        if ($apple->length > 0) {
            $push(trim($apple->item(0)->value ?? ''), 55, 'apple_touch');
        }

        // 7. OG / Twitter images (low — frequently social cards, not wordmarks)
        foreach (['//meta[@property="og:image"]/@content', '//meta[@property="og:image:secure_url"]/@content', '//meta[@name="twitter:image"]/@content'] as $mq) {
            $meta = $xpath->query($mq);
            if ($meta->length > 0) {
                $push(trim($meta->item(0)->value ?? ''), 35, 'og_twitter');
            }
        }

        // Merge duplicate URLs by max score, then sort and cap
        $mergedByUrl = [];
        foreach ($scored as $row) {
            $key = $row['url'];
            if (! isset($mergedByUrl[$key]) || $mergedByUrl[$key]['score'] < $row['score']) {
                $mergedByUrl[$key] = $row;
            }
        }
        $merged = array_values($mergedByUrl);
        usort($merged, fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $merged = array_slice($merged, 0, self::MAX_LOGO_CANDIDATES);
        $logoCandidates = array_map(fn (array $r) => $r['url'], $merged);
        $logoCandidateEntries = $merged;

        // Pick the best URL candidate if no SVG was found
        if (! $logoSvg && $logoCandidates !== []) {
            $logoUrl = $this->pickBestRasterLogoUrl($logoCandidates);
        }

        return [
            'favicon' => $favicon,
            'logo_url' => $logoUrl,
            'logo_svg' => $logoSvg,
            'logo_candidates' => $logoCandidates,
            'logo_candidate_entries' => $logoCandidateEntries,
        ];
    }

    /**
     * @return list<string>
     */
    protected function extractLogoUrlsFromJsonLd(string $html): array
    {
        $urls = [];
        if (! preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $blocks)) {
            return [];
        }
        foreach ($blocks[1] as $raw) {
            $raw = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($raw === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                continue;
            }
            $this->walkJsonLdForLogo($decoded, $urls);
        }

        return array_values(array_unique(array_filter($urls)));
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $urls
     */
    protected function walkJsonLdForLogo(mixed $node, array &$urls): void
    {
        if (! is_array($node)) {
            return;
        }
        if (isset($node['@graph']) && is_array($node['@graph'])) {
            foreach ($node['@graph'] as $g) {
                $this->walkJsonLdForLogo($g, $urls);
            }
        }
        foreach ($node as $k => $v) {
            if ($k === '@graph') {
                continue;
            }
            if ($k === 'logo') {
                $this->pushJsonLdLogoValue($v, $urls);
            } elseif (is_array($v)) {
                $this->walkJsonLdForLogo($v, $urls);
            }
        }
    }

    /**
     * @param  list<string>  $urls
     */
    protected function pushJsonLdLogoValue(mixed $v, array &$urls): void
    {
        if (is_string($v) && strlen($v) > 4) {
            $urls[] = $v;

            return;
        }
        if (! is_array($v)) {
            return;
        }
        if (isset($v['url'])) {
            if (is_string($v['url'])) {
                $urls[] = $v['url'];
            } elseif (is_array($v['url'])) {
                foreach ($v['url'] as $u) {
                    if (is_string($u)) {
                        $urls[] = $u;
                    }
                }
            }
        }
        if (isset($v['@id']) && is_string($v['@id']) && str_starts_with($v['@id'], 'http')) {
            $urls[] = $v['@id'];
        }
    }

    /**
     * @return list<string>
     */
    protected function extractUrlsFromCssBackground(string $style): array
    {
        $urls = [];
        if ($style === '' || ! preg_match_all('#url\s*\(\s*["\']?([^"\')\s]+)["\']?\s*\)#i', $style, $m)) {
            return [];
        }
        foreach ($m[1] as $u) {
            $u = trim($u);
            if ($u !== '' && ! str_starts_with($u, 'data:')) {
                $urls[] = $u;
            }
        }

        return $urls;
    }

    protected function isLogoLikeTokenString(string $haystack): bool
    {
        $h = strtolower($haystack);

        return (bool) preg_match(
            '/\b(logo|brand|site-logo|navbar-brand|custom-logo|header-logo|heading-logo|header__heading|site-header|shopify-section-header)\b/i',
            $h
        );
    }

    protected function extractBestImgCandidateUrl(\DOMElement $img, string $baseUrl): string
    {
        foreach (['src', 'data-src', 'data-lazy-src', 'data-original', 'data-image'] as $attr) {
            $v = trim($img->getAttribute($attr));
            if ($v !== '' && ! str_starts_with($v, 'data:')) {
                return $this->normalizeHttpUrl($this->resolveUrl($v, $baseUrl));
            }
        }
        foreach (['srcset', 'data-srcset'] as $attr) {
            $srcset = $img->getAttribute($attr);
            if ($srcset !== '') {
                $u = $this->firstUrlFromSrcset($srcset);
                if ($u !== '') {
                    return $this->normalizeHttpUrl($this->resolveUrl($u, $baseUrl));
                }
            }
        }

        return '';
    }

    protected function firstUrlFromSrcset(string $srcset): string
    {
        $srcset = html_entity_decode($srcset, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $first = trim(explode(',', $srcset)[0] ?? '');
        $first = preg_replace('/\s+[\d.]+[wx]\s*$/i', '', $first) ?? $first;

        return trim($first);
    }

    protected function normalizeHttpUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }
        if (preg_match('#^http://#i', $url)) {
            return 'https://'.substr($url, 7);
        }

        return $url;
    }

    /**
     * Prefer SVG URLs, then paths that look like brand marks vs social share art.
     *
     * @param  list<string>  $candidates  Already ordered by extraction score
     */
    protected function pickBestRasterLogoUrl(array $candidates): string
    {
        foreach ($candidates as $c) {
            if (str_contains(strtolower($c), '.svg')) {
                return $c;
            }
        }
        foreach ($candidates as $c) {
            $lower = strtolower($c);
            if (str_contains($lower, 'social') || str_contains($lower, 'og-image') || str_contains($lower, 'share')) {
                continue;
            }

            return $c;
        }

        return $candidates[0];
    }

    // ── Colors ───────────────────────────────────────────────────

    protected function extractColors(string $html): array
    {
        $hexPattern = '/#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})\b/';
        preg_match_all($hexPattern, $html, $matches);
        $colors = array_unique($matches[0] ?? []);

        $normalized = [];
        foreach ($colors as $c) {
            if (strlen($c) === 4) {
                $c = '#'.$c[1].$c[1].$c[2].$c[2].$c[3].$c[3];
            }
            $upper = strtoupper($c);
            if (in_array($upper, ['#FFFFFF', '#000000', '#FFF', '#000'])) {
                continue;
            }
            $normalized[] = $c;
        }

        return array_slice(array_values(array_unique($normalized)), 0, 10);
    }

    // ── Fonts ────────────────────────────────────────────────────

    protected function extractFonts(string $html, DOMDocument $dom, DOMXPath $xpath): array
    {
        $fonts = [];

        // Inline font-family declarations
        preg_match_all('/font-family\s*:\s*([^;}"\']+)/i', $html, $styleMatches);
        foreach ($styleMatches[1] ?? [] as $match) {
            $parts = array_map('trim', explode(',', $match));
            foreach ($parts as $p) {
                $p = trim($p, ' "\'');
                if ($p !== '' && ! $this->isGenericFont($p) && ! in_array($p, $fonts, true)) {
                    $fonts[] = $p;
                }
            }
        }

        // Google Fonts links
        $googleFontPattern = '/fonts\.googleapis\.com\/css2?\?family=([^&"\'>\s]+)/i';
        preg_match_all($googleFontPattern, $html, $googleMatches);
        foreach ($googleMatches[1] ?? [] as $param) {
            $decoded = urldecode($param);
            $names = preg_split('/[|&]/', $decoded);
            foreach ($names as $name) {
                $name = preg_replace('/:[\w,@;]+/', '', $name);
                $name = str_replace('+', ' ', trim($name));
                if ($name !== '' && ! in_array($name, $fonts, true)) {
                    $fonts[] = $name;
                }
            }
        }

        // Google Fonts <link> elements
        $links = $xpath->query('//link[@href][contains(@href,"fonts.googleapis")]');
        foreach ($links as $link) {
            $href = $link->getAttribute('href') ?? '';
            if (preg_match_all($googleFontPattern, $href, $m)) {
                foreach ($m[1] ?? [] as $param) {
                    $decoded = urldecode($param);
                    $names = preg_split('/[|&]/', $decoded);
                    foreach ($names as $name) {
                        $name = preg_replace('/:[\w,@;]+/', '', $name);
                        $name = str_replace('+', ' ', trim($name));
                        if ($name !== '' && ! in_array($name, $fonts, true)) {
                            $fonts[] = $name;
                        }
                    }
                }
            }
        }

        return array_slice(array_values(array_unique($fonts)), 0, 12);
    }

    protected function isGenericFont(string $font): bool
    {
        $generics = ['serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui', 'ui-serif', 'ui-sans-serif', 'ui-monospace', 'ui-rounded', 'inherit', 'initial', 'unset', 'revert'];

        return in_array(strtolower($font), $generics, true);
    }

    // ── Headlines ────────────────────────────────────────────────

    protected function extractHeadlines(DOMXPath $xpath): array
    {
        $headlines = [];
        foreach (['//h1', '//h2'] as $selector) {
            foreach ($xpath->query($selector) as $el) {
                $t = trim($el->textContent ?? '');
                if ($t !== '' && strlen($t) < 500) {
                    $headlines[] = $t;
                }
            }
        }

        return array_slice($headlines, 0, 10);
    }

    // ── Brand bio ────────────────────────────────────────────────

    protected function extractBrandBio(DOMDocument $dom, DOMXPath $xpath): ?string
    {
        // meta description
        $metaDesc = $xpath->query('//meta[@name="description"]/@content');
        if ($metaDesc->length > 0) {
            $desc = trim($metaDesc->item(0)->value ?? '');
            if (strlen($desc) > 20) {
                return $desc;
            }
        }

        // og:description
        $ogDesc = $xpath->query('//meta[@property="og:description"]/@content');
        if ($ogDesc->length > 0) {
            $desc = trim($ogDesc->item(0)->value ?? '');
            if (strlen($desc) > 20) {
                return $desc;
            }
        }

        return null;
    }

    // ── URL resolution ───────────────────────────────────────────

    protected function resolveUrl(string $url, string $baseUrl): string
    {
        if ($url === '') {
            return '';
        }
        if (str_starts_with($url, 'data:')) {
            return '';
        }
        if (str_starts_with($url, '//')) {
            return 'https:'.$url;
        }
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }
        if (str_starts_with($url, '/')) {
            return rtrim($baseUrl, '/').$url;
        }

        return rtrim($baseUrl, '/').'/'.ltrim($url, '/');
    }
}
