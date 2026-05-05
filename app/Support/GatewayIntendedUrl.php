<?php

namespace App\Support;

/**
 * URLs stored as {@code intended_url} when sending users to /gateway must resume to real app pages,
 * not JSON/XHR endpoints (otherwise gateway completion navigates to raw API responses).
 */
final class GatewayIntendedUrl
{
    /**
     * True when this path must never be used as the post-gateway resume destination.
     */
    public static function shouldDiscardPath(string $path): bool
    {
        $path = trim($path);
        if ($path === '' || ! str_starts_with($path, '/app')) {
            return false;
        }

        if (str_starts_with($path, '/app/api')) {
            return true;
        }

        if (str_starts_with($path, '/app/download-bucket')) {
            return true;
        }

        return false;
    }
}
