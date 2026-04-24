<?php

namespace App\Studio\Rendering;

use App\Studio\Rendering\Exceptions\StudioFontResolutionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Local disk cache for curated Google font binaries (never passes remote URLs to FFmpeg/Imagick/GD).
 */
final class StudioGoogleFontFileCache
{
    /**
     * @return non-empty-string Absolute path to a readable .ttf/.otf under the Google font cache directory.
     */
    public function materializeFromRegistrySlug(string $slug, string $downloadUrl): string
    {
        $slug = preg_replace('/[^a-zA-Z0-9_-]/', '', $slug) ?? '';
        if ($slug === '') {
            throw new StudioFontResolutionException('google_font_slug_invalid', 'Invalid Google font registry slug.', []);
        }
        $url = trim($downloadUrl);
        if ($url === '' || ! str_starts_with($url, 'https://')) {
            throw new StudioFontResolutionException('google_font_url_invalid', 'Google font download URL must be an https URL.', []);
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        $allowedHost = str_ends_with($host, 'githubusercontent.com')
            || str_ends_with($host, 'fonts.gstatic.com')
            || str_ends_with($host, 'googleapis.com');
        if ($host === '' || ! $allowedHost) {
            throw new StudioFontResolutionException(
                'google_font_host_not_allowed',
                'Google font download host is not allow-listed.',
                ['host' => $host],
            );
        }

        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
        $allowed = $this->allowedExtensionsList();
        if (! in_array($ext, $allowed, true)) {
            throw new StudioFontResolutionException(
                'google_font_bad_extension',
                'Google font download must point to .ttf or .otf (got .'.$ext.').',
                [],
            );
        }

        $dir = $this->googleCacheRoot();
        File::ensureDirectoryExists($dir);
        $hash = substr(hash('sha256', $url), 0, 24);
        $fileName = 'g_'.$slug.'_'.$hash.'.'.$ext;
        $fullPath = $dir.DIRECTORY_SEPARATOR.$fileName;

        if (is_file($fullPath) && is_readable($fullPath) && filesize($fullPath) > 32) {
            if (config('studio_rendering.font_pipeline_verbose_log')) {
                Log::info('[FONT_DEBUG] Google font staged', [
                    'slug' => $slug,
                    'local_path' => $fullPath,
                    'exists' => true,
                    'readable' => true,
                    'extension' => strtolower(pathinfo($fullPath, PATHINFO_EXTENSION)),
                    'cache' => 'hit',
                ]);
            }

            return $fullPath;
        }

        $response = Http::timeout(60)
            ->withHeaders(['User-Agent' => 'JackpotStudioFontCache/1.0'])
            ->get($url);
        if (! $response->successful()) {
            throw new StudioFontResolutionException(
                'google_font_download_failed',
                'Failed to download Google font file (HTTP '.$response->status().').',
                ['url' => $url],
            );
        }
        $bytes = $response->body();
        if ($bytes === '') {
            throw new StudioFontResolutionException(
                'google_font_download_failed',
                'Failed to download Google font file.',
                ['url' => $url],
            );
        }

        if (file_put_contents($fullPath, $bytes) === false) {
            throw new StudioFontResolutionException('google_font_cache_write_failed', 'Could not write Google font to local cache.', [
                'target' => $fullPath,
            ]);
        }
        @chmod($fullPath, 0644);
        if (! is_readable($fullPath)) {
            throw new StudioFontResolutionException('google_font_cache_not_readable', 'Cached Google font is not readable.', [
                'path' => $fullPath,
            ]);
        }

        if (config('studio_rendering.font_pipeline_verbose_log')) {
            Log::info('[FONT_DEBUG] Google font staged', [
                'slug' => $slug,
                'local_path' => $fullPath,
                'exists' => is_file($fullPath),
                'readable' => is_readable($fullPath),
                'extension' => strtolower(pathinfo($fullPath, PATHINFO_EXTENSION)),
                'cache' => 'miss_written',
            ]);
        }

        return $fullPath;
    }

    /**
     * @return non-empty-string
     */
    public function googleCacheRoot(): string
    {
        $raw = trim((string) config('studio_rendering.font_cache_dir', 'studio/font-cache'));
        $sub = trim($raw, DIRECTORY_SEPARATOR.'/\\');
        $parent = rtrim(storage_path('app'.DIRECTORY_SEPARATOR.$sub), DIRECTORY_SEPARATOR.'/\\');

        return $parent.DIRECTORY_SEPARATOR.'google';
    }

    /**
     * @return list<string>
     */
    private function allowedExtensionsList(): array
    {
        $raw = trim((string) config('studio_rendering.allowed_font_extensions', 'ttf,otf'));
        $parts = array_filter(array_map('trim', explode(',', strtolower($raw))));

        return $parts !== [] ? array_values($parts) : ['ttf', 'otf'];
    }
}
