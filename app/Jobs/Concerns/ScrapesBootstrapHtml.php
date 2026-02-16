<?php

namespace App\Jobs\Concerns;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;

/**
 * Shared HTML scraping logic for Brand Bootstrap pipeline.
 */
trait ScrapesBootstrapHtml
{
    protected function fetchHtml(string $url): string
    {
        $response = Http::timeout(10)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; BrandBootstrap/1.0; +https://jackpot.dam)',
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

    protected function extractStructured(string $html, string $baseUrl): array
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        return [
            'meta' => $this->extractMeta($dom, $xpath),
            'branding' => $this->extractBranding($dom, $xpath, $baseUrl),
            'headlines' => $this->extractHeadlines($xpath),
            'navigation' => $this->extractNavigation($xpath),
            'colors_detected' => $this->extractColors($html),
            'font_families' => $this->extractFonts($html, $dom, $xpath),
        ];
    }

    protected function extractMeta(DOMDocument $dom, DOMXPath $xpath): array
    {
        $result = ['title' => '', 'description' => '', 'og_title' => '', 'og_image' => ''];

        $title = $xpath->query('//title');
        if ($title->length > 0) {
            $result['title'] = trim($title->item(0)->textContent ?? '');
        }

        $metaDesc = $xpath->query('//meta[@name="description"]/@content');
        if ($metaDesc->length > 0) {
            $result['description'] = trim($metaDesc->item(0)->value ?? '');
        }

        $ogTitle = $xpath->query('//meta[@property="og:title"]/@content');
        if ($ogTitle->length > 0) {
            $result['og_title'] = trim($ogTitle->item(0)->value ?? '');
        }

        $ogImage = $xpath->query('//meta[@property="og:image"]/@content');
        if ($ogImage->length > 0) {
            $result['og_image'] = trim($ogImage->item(0)->value ?? '');
        }

        return $result;
    }

    protected function extractBranding(DOMDocument $dom, DOMXPath $xpath, string $baseUrl): array
    {
        $favicon = '';
        $logoCandidates = [];

        $links = $xpath->query('//link[@rel="icon" or @rel="shortcut icon"]/@href');
        if ($links->length > 0) {
            $favicon = $this->resolveUrl(trim($links->item(0)->value ?? ''), $baseUrl);
        }

        $imgs = $xpath->query('//img');
        foreach ($imgs as $img) {
            $src = $img->getAttribute('src') ?? '';
            $class = strtolower($img->getAttribute('class') ?? '');
            $id = strtolower($img->getAttribute('id') ?? '');
            if (str_contains($class, 'logo') || str_contains($id, 'logo') || str_contains($src, 'logo')) {
                $resolved = $this->resolveUrl($src, $baseUrl);
                if ($resolved && ! in_array($resolved, $logoCandidates)) {
                    $logoCandidates[] = $resolved;
                    if (count($logoCandidates) >= 5) {
                        break;
                    }
                }
            }
        }

        return [
            'favicon' => $favicon,
            'logo_candidates' => $logoCandidates,
        ];
    }

    protected function extractHeadlines(DOMXPath $xpath): array
    {
        $h1 = [];
        $h2 = [];

        foreach ($xpath->query('//h1') as $el) {
            $t = trim($el->textContent ?? '');
            if ($t !== '') {
                $h1[] = $t;
            }
        }
        foreach ($xpath->query('//h2') as $el) {
            $t = trim($el->textContent ?? '');
            if ($t !== '') {
                $h2[] = $t;
            }
        }

        return ['h1' => $h1, 'h2' => $h2];
    }

    protected function extractNavigation(DOMXPath $xpath): array
    {
        $links = [];
        $nav = $xpath->query('//nav | //header//nav | //*[@role="navigation"]')->item(0);
        if (! $nav) {
            return ['links' => []];
        }
        foreach ($xpath->query('.//a[@href]', $nav) as $a) {
            $href = $a->getAttribute('href') ?? '';
            $label = trim($a->textContent ?? '');
            if ($label !== '' || $href !== '') {
                $links[] = ['label' => $label, 'href' => $href];
            }
        }

        return ['links' => array_slice($links, 0, 20)];
    }

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
            $normalized[] = $c;
        }

        return array_slice(array_unique($normalized), 0, 10);
    }

    /**
     * Extract font families from inline style font-family declarations and Google Fonts link hrefs.
     *
     * @return array<string>
     */
    protected function extractFonts(string $html, DOMDocument $dom, DOMXPath $xpath): array
    {
        $fonts = [];

        // Inline style font-family
        $fontFamilyPattern = '/font-family\s*:\s*([^;}"\']+)/i';
        preg_match_all($fontFamilyPattern, $html, $styleMatches);
        foreach ($styleMatches[1] ?? [] as $match) {
            $parts = array_map('trim', explode(',', $match));
            foreach ($parts as $p) {
                $p = trim($p, ' "\'');
                if ($p !== '' && ! in_array($p, $fonts, true)) {
                    $fonts[] = $p;
                }
            }
        }

        // Google Fonts: fonts.googleapis.com/css?family=Font+Name:weight
        $googleFontPattern = '/fonts\.googleapis\.com\/css\?family=([^&"\']+)/i';
        preg_match_all($googleFontPattern, $html, $googleMatches);
        foreach ($googleMatches[1] ?? [] as $param) {
            $decoded = urldecode($param);
            $names = explode('|', $decoded);
            foreach ($names as $name) {
                $name = trim(preg_replace('/:\d+/', '', $name));
                $name = str_replace('+', ' ', $name);
                if ($name !== '' && ! in_array($name, $fonts, true)) {
                    $fonts[] = $name;
                }
            }
        }

        // link href with fonts.googleapis.com
        $links = $xpath->query('//link[@href][contains(@href,"fonts.googleapis")]');
        foreach ($links as $link) {
            $href = $link->getAttribute('href') ?? '';
            if (preg_match_all($googleFontPattern, $href, $m)) {
                foreach ($m[1] ?? [] as $param) {
                    $decoded = urldecode($param);
                    $names = explode('|', $decoded);
                    foreach ($names as $name) {
                        $name = trim(preg_replace('/:\d+/', '', $name));
                        $name = str_replace('+', ' ', $name);
                        if ($name !== '' && ! in_array($name, $fonts, true)) {
                            $fonts[] = $name;
                        }
                    }
                }
            }
        }

        return array_slice(array_values(array_unique($fonts)), 0, 12);
    }

    protected function resolveUrl(string $url, string $baseUrl): string
    {
        if ($url === '') {
            return '';
        }
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }
        $base = parse_url($baseUrl);
        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'] ?? '';
        $path = $base['path'] ?? '/';
        if (str_starts_with($url, '/')) {
            return $scheme . '://' . $host . $url;
        }
        $dir = rtrim(dirname($path), '/');
        if ($dir === '.' || $dir === '') {
            $dir = '';
        }
        $base = $scheme . '://' . $host . ($dir ?: '') . '/';

        return rtrim($base, '/') . '/' . ltrim($url, '/');
    }
}
