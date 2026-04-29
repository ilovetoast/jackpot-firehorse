<?php

namespace App\Support;

/**
 * Relative paths for {@see \Illuminate\Support\Facades\Storage} disk
 * `studio_layer_extraction`. S3 uses an optional key prefix; local keeps session id at top level.
 */
final class StudioLayerExtractionStoragePaths
{
    /**
     * Path to a file under a session, e.g. `uuid/cand_mask.png` (local) or
     * `studio_layer_extraction/uuid/cand_mask.png` (S3 with default prefix).
     */
    public static function relative(string $sessionId, string $fileName): string
    {
        $sessionId = trim($sessionId);
        $fileName = ltrim($fileName, '/');
        if ($fileName === '') {
            $fileName = 'unnamed';
        }
        $prefix = self::s3KeyPrefix();
        if ($prefix !== null) {
            return $prefix.'/'.$sessionId.'/'.$fileName;
        }

        return $sessionId.'/'.$fileName;
    }

    /**
     * Directory path for a session (for makeDirectory / deleteDirectory).
     */
    public static function sessionDirectory(string $sessionId): string
    {
        $sessionId = trim($sessionId);
        $prefix = self::s3KeyPrefix();
        if ($prefix !== null) {
            return $prefix.'/'.$sessionId;
        }

        return $sessionId;
    }

    private static function s3KeyPrefix(): ?string
    {
        if ((string) config('filesystems.disks.studio_layer_extraction.driver', 'local') !== 's3') {
            return null;
        }
        $p = trim((string) config('studio_layer_extraction.s3_path_prefix', 'studio_layer_extraction'), '/');

        return $p === '' ? null : $p;
    }
}
