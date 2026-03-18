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
    /**
     * Crawl a URL and return structured data for snapshot building.
     *
     * @return array{logo_url: ?string, logo_svg: ?string, logo_candidates: array, primary_colors: string[], detected_fonts: string[], hero_headlines: string[], brand_bio: ?string, favicon: ?string}
     */
    public function crawl(string $url): array
    {
        $url = $this->normalizeUrl($url);

        $result = [
            'logo_url' => null,
            'logo_svg' => null,
            'logo_candidates' => [],
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
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $baseUrl = $this->getBaseUrl($url);

        $branding = $this->extractBranding($dom, $xpath, $baseUrl, $html);
        $result['logo_url'] = $branding['logo_url'];
        $result['logo_svg'] = $branding['logo_svg'];
        $result['logo_candidates'] = $branding['logo_candidates'];
        $result['favicon'] = $branding['favicon'];

        $result['primary_colors'] = $this->extractColors($html);
        $result['detected_fonts'] = $this->extractFonts($html, $dom, $xpath);
        $result['hero_headlines'] = $this->extractHeadlines($xpath);
        $result['brand_bio'] = $this->extractBrandBio($dom, $xpath);

        Log::channel('pipeline')->info('[BrandWebsiteCrawlerService] Crawl complete', [
            'url' => $url,
            'logo_url' => $result['logo_url'],
            'has_svg_logo' => ! empty($result['logo_svg']),
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
            return $this->downloadImageLogoAsAsset($logoUrl, $brand, $draft);
        }

        return null;
    }

    protected function saveSvgLogoAsAsset(string $svgCode, Brand $brand, BrandModelVersion $draft): ?Asset
    {
        try {
            $svgCode = trim($svgCode);
            if (! str_starts_with($svgCode, '<svg') && ! str_starts_with($svgCode, '<?xml')) {
                return null;
            }

            $tenant = $brand->tenant;
            if (! $tenant?->uuid) {
                return null;
            }

            $asset = $this->createProgrammaticAsset($brand, [
                'title' => 'Website Logo (SVG)',
                'original_filename' => 'logo.svg',
                'mime_type' => 'image/svg+xml',
                'size_bytes' => strlen($svgCode),
            ]);

            $pathGenerator = app(AssetPathGenerator::class);
            $path = $pathGenerator->generateOriginalPath($tenant, $asset, 1, 'svg');

            Storage::disk('s3')->put($path, $svgCode, 'private');

            $this->createVersionAndFinalize($asset, $path, strlen($svgCode), 'image/svg+xml');
            $this->attachAssetToDraft($draft, $asset);

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

    protected function downloadImageLogoAsAsset(string $logoUrl, Brand $brand, BrandModelVersion $draft): ?Asset
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; BrandCrawler/1.0)'])
                ->get($logoUrl);

            if (! $response->successful()) {
                return null;
            }

            $body = $response->body();
            $contentType = $response->header('Content-Type') ?? 'image/png';
            $mimeType = explode(';', $contentType)[0];

            $isSvg = str_contains($mimeType, 'svg') || str_starts_with(trim($body), '<svg') || str_starts_with(trim($body), '<?xml');
            if ($isSvg) {
                return $this->saveSvgLogoAsAsset($body, $brand, $draft);
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
                'title' => 'Website Logo',
                'original_filename' => 'logo.'.$ext,
                'mime_type' => $mimeType,
                'size_bytes' => strlen($body),
            ]);

            $pathGenerator = app(AssetPathGenerator::class);
            $path = $pathGenerator->generateOriginalPath($tenant, $asset, 1, $ext);

            Storage::disk('s3')->put($path, $body, 'private');

            $this->createVersionAndFinalize($asset, $path, strlen($body), $mimeType);
            $this->attachAssetToDraft($draft, $asset);
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

    protected function attachAssetToDraft(BrandModelVersion $draft, Asset $asset): void
    {
        $exists = BrandModelVersionAsset::where('brand_model_version_id', $draft->id)
            ->where('asset_id', $asset->id)
            ->where('builder_context', 'logo_reference')
            ->exists();

        if (! $exists) {
            BrandModelVersionAsset::create([
                'brand_model_version_id' => $draft->id,
                'asset_id' => $asset->id,
                'builder_context' => 'logo_reference',
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
            'builder_context' => 'logo_reference',
            'source' => 'website_crawl',
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
                'User-Agent' => 'Mozilla/5.0 (compatible; BrandCrawler/1.0; +https://jackpot.dam)',
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
            $url = 'https://' . $url;
        }

        return rtrim($url, '/');
    }

    protected function getBaseUrl(string $url): string
    {
        $parsed = parse_url($url);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';

        return $scheme . '://' . $host;
    }

    // ── Branding (logo + favicon) ─────────────────────────────────

    protected function extractBranding(DOMDocument $dom, DOMXPath $xpath, string $baseUrl, string $html): array
    {
        $favicon = '';
        $logoCandidates = [];
        $logoUrl = null;
        $logoSvg = null;

        // Favicon
        $links = $xpath->query('//link[@rel="icon" or @rel="shortcut icon"]/@href');
        if ($links->length > 0) {
            $favicon = $this->resolveUrl(trim($links->item(0)->value ?? ''), $baseUrl);
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

        // 2. SVG in <a> linking to homepage (common logo pattern)
        if (! $logoSvg) {
            $homeLinks = $xpath->query('//a[@href="/" or @href="' . $baseUrl . '" or @href="' . $baseUrl . '/"]');
            foreach ($homeLinks as $link) {
                $svgs = $xpath->query('.//svg', $link);
                if ($svgs->length > 0) {
                    $svgNode = $svgs->item(0);
                    $svgHtml = $dom->saveHTML($svgNode);
                    if ($svgHtml && strlen($svgHtml) > 20 && strlen($svgHtml) < 100000) {
                        $logoSvg = $svgHtml;
                        break;
                    }
                }
                // Also check for <img> inside homepage links
                $imgs = $xpath->query('.//img', $link);
                foreach ($imgs as $img) {
                    $src = $img->getAttribute('src') ?? '';
                    if ($src) {
                        $resolved = $this->resolveUrl($src, $baseUrl);
                        if ($resolved && ! in_array($resolved, $logoCandidates)) {
                            $logoCandidates[] = $resolved;
                        }
                    }
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

        // 4. <img> tags with logo in class/id/src
        $imgs = $xpath->query('//img');
        foreach ($imgs as $img) {
            $src = $img->getAttribute('src') ?? '';
            $class = strtolower($img->getAttribute('class') ?? '');
            $id = strtolower($img->getAttribute('id') ?? '');
            $alt = strtolower($img->getAttribute('alt') ?? '');
            if (str_contains($class, 'logo') || str_contains($id, 'logo') || str_contains($src, 'logo') || str_contains($alt, 'logo')) {
                $resolved = $this->resolveUrl($src, $baseUrl);
                if ($resolved && ! in_array($resolved, $logoCandidates)) {
                    $logoCandidates[] = $resolved;
                    if (count($logoCandidates) >= 5) {
                        break;
                    }
                }
            }
        }

        // 5. OG image as last-resort candidate
        $ogImage = $xpath->query('//meta[@property="og:image"]/@content');
        if ($ogImage->length > 0) {
            $ogUrl = $this->resolveUrl(trim($ogImage->item(0)->value ?? ''), $baseUrl);
            if ($ogUrl && ! in_array($ogUrl, $logoCandidates)) {
                $logoCandidates[] = $ogUrl;
            }
        }

        // Pick the best URL candidate if no SVG was found
        if (! $logoSvg && ! empty($logoCandidates)) {
            $logoUrl = $logoCandidates[0];
            // Prefer SVG file URLs over raster
            foreach ($logoCandidates as $candidate) {
                if (str_contains(strtolower($candidate), '.svg')) {
                    $logoUrl = $candidate;
                    break;
                }
            }
        }

        return [
            'favicon' => $favicon,
            'logo_url' => $logoUrl,
            'logo_svg' => $logoSvg,
            'logo_candidates' => $logoCandidates,
        ];
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
                $c = '#' . $c[1] . $c[1] . $c[2] . $c[2] . $c[3] . $c[3];
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
            return 'https:' . $url;
        }
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }
        if (str_starts_with($url, '/')) {
            return rtrim($baseUrl, '/') . $url;
        }

        return rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
    }
}
