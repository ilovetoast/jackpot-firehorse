<?php

namespace App\Support;

class CdnUrl
{
    /**
     * Build a CDN URL for the given path.
     *
     * Returns https://{cloudfront_domain}/{path}. In local environment,
     * returns the Storage/S3 URL (no signing; existing logic unchanged).
     * Does NOT append any query parameters (e.g. no ?v=). For signed URLs,
     * any query string must be appended before signing so CloudFront sees
     * the same resource URL; admin thumbnails intentionally use no ?v=.
     *
     * @param  string  $path  Path relative to CDN root (e.g. "assets/tenant/123/file.jpg")
     */
    public static function url(string $path): string
    {
        $path = ltrim($path, '/');

        // Local: return presigned S3 URL (temporaryUrl) so thumbnails load without CORS
        if (app()->environment('local')) {
            $ttl = (int) config('assets.delivery.local_presign_ttl', 900);
            return \Illuminate\Support\Facades\Storage::disk('s3')->temporaryUrl($path, now()->addSeconds($ttl));
        }

        $domain = config('cloudfront.domain');
        if (empty($domain)) {
            return \Illuminate\Support\Facades\Storage::disk('s3')->url($path);
        }

        return 'https://' . $domain . '/' . $path;
    }
}
