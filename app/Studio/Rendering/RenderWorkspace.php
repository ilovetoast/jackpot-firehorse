<?php

namespace App\Studio\Rendering;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Per-export temp directory under storage (deterministic prefix + random suffix).
 */
final class RenderWorkspace
{
    public static function allocate(int $exportJobId): string
    {
        $parent = trim((string) config('studio_rendering.render_workspace_parent', ''));
        if ($parent !== '') {
            $base = rtrim($parent, DIRECTORY_SEPARATOR);
        } else {
            $sub = trim((string) config('studio_rendering.render_workspace_subdir', 'tmp/studio-ffmpeg-native'), DIRECTORY_SEPARATOR.'/\\');
            $base = storage_path('app'.DIRECTORY_SEPARATOR.$sub);
        }
        $dir = $base.DIRECTORY_SEPARATOR.'job-'.$exportJobId.'-'.Str::lower(Str::random(10));
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    public static function purge(string $workspacePath): void
    {
        if ($workspacePath !== '' && is_dir($workspacePath)) {
            File::deleteDirectory($workspacePath);
        }
    }
}
