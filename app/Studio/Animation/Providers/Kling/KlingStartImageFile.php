<?php

namespace App\Studio\Animation\Providers\Kling;

use Illuminate\Support\Facades\Storage;

/**
 * Resolves a start-frame image to a local path for the official Kling image-to-video API.
 */
final class KlingStartImageFile
{
    public static function materializeToTempFile(string $disk, string $path): string
    {
        if ($disk === 'local' || $disk === 'public') {
            /** @phpstan-ignore-next-line */
            return Storage::disk($disk)->path($path);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'sa_kling_');
        if ($tmp === false) {
            throw new \RuntimeException('Temp file failed.');
        }
        $bytes = Storage::disk($disk)->get($path);
        file_put_contents($tmp, $bytes);

        return $tmp;
    }
}
