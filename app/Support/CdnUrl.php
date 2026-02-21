<?php

namespace App\Support;

class CdnUrl
{
    /**
     * Build a CDN URL for the given path.
     *
     * Returns https://{cloudfront_domain}/{path}. In local environment,
     * returns the Storage/S3 URL (no signing; existing logic unchanged).
     *
     * @param  string  $path  Path relative to CDN root (e.g. "assets/tenant/123/file.jpg")
     */
    public static function url(string $path): string
    {
        $path = ltrim($path, '/');

        // Local: skip CloudFront, return normal S3/storage URL per task requirements
        if (app()->environment('local')) {
            return \Illuminate\Support\Facades\Storage::disk('s3')->url($path);
        }

        $domain = config('cloudfront.domain');
        if (empty($domain)) {
            return \Illuminate\Support\Facades\Storage::disk('s3')->url($path);
        }

        return 'https://' . $domain . '/' . $path;
    }
}
