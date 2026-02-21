<?php

if (! function_exists('cdn_url')) {
    /**
     * Build a CDN URL for the given path.
     *
     * Returns https://{cloudfront_domain}/{path}. In local environment,
     * returns the Storage/S3 URL (no signing; existing logic unchanged).
     *
     * @param  string  $path  Path relative to CDN root (e.g. "assets/tenant/123/file.jpg")
     */
    function cdn_url(string $path): string
    {
        return \App\Support\CdnUrl::url($path);
    }
}
