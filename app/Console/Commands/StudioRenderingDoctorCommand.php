<?php

namespace App\Console\Commands;

use App\Studio\Rendering\StudioRenderingFontFileCache;
use App\Studio\Rendering\StudioGoogleFontFileCache;
use App\Studio\Rendering\StudioRenderingFontPaths;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class StudioRenderingDoctorCommand extends Command
{
    protected $signature = 'studio:rendering:doctor';

    protected $description = 'Diagnose FFmpeg, fontconfig-related tooling, PHP image extensions, and Studio font resolution';

    public function handle(): int
    {
        $ffmpeg = trim((string) config('studio_rendering.ffmpeg_binary', ''));
        if ($ffmpeg === '') {
            $ffmpeg = 'ffmpeg';
        }
        $this->line('ffmpeg: '.$this->whichOrNote($ffmpeg));

        $ffprobe = trim((string) config('studio_rendering.ffprobe_binary', ''));
        if ($ffprobe === '') {
            $ffprobe = 'ffprobe';
        }
        $this->line('ffprobe: '.$this->whichOrNote($ffprobe));

        $this->line('PHP imagick loaded: '.(extension_loaded('imagick') ? 'yes' : 'no'));
        $this->line('PHP gd loaded: '.(extension_loaded('gd') ? 'yes' : 'no'));

        $def = StudioRenderingFontPaths::effectiveDefaultFontPath();
        $this->line('Default font path: '.$def.' ('.(is_readable($def) ? 'readable' : 'NOT readable').')');

        $tenantRoot = (new StudioRenderingFontFileCache)->fontCacheRoot();
        File::ensureDirectoryExists($tenantRoot);
        $this->line('Tenant font cache: '.$tenantRoot.' (writable: '.(is_writable($tenantRoot) ? 'yes' : 'no').')');

        $googleRoot = (new StudioGoogleFontFileCache)->googleCacheRoot();
        File::ensureDirectoryExists($googleRoot);
        $this->line('Google font cache: '.$googleRoot.' (writable: '.(is_writable($googleRoot) ? 'yes' : 'no').')');

        $this->line('fontconfig / fc-list: '.$this->whichOrNote('fc-list').' (native export does not rely on fontconfig for font resolution)');
        $this->line('freetype: bundled with PHP gd/imagick; FFmpeg uses its own FreeType build');

        return self::SUCCESS;
    }

    private function whichOrNote(string $binary): string
    {
        $out = @shell_exec('command -v '.escapeshellarg($binary).' 2>/dev/null');

        return is_string($out) && trim($out) !== '' ? trim($out) : '(not found on PATH — set STUDIO_RENDERING_FFMPEG_BINARY / FFPROBE)';
    }
}
