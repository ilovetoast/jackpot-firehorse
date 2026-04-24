<?php

namespace App\Console\Commands;

use App\Studio\Rendering\StudioRenderingFontPaths;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class StudioFontsCheckCommand extends Command
{
    protected $signature = 'studio:fonts:check';

    protected $description = 'Verify bundled Studio fonts exist, default font resolves, and font caches are writable';

    public function handle(): int
    {
        $errors = 0;
        /** @var array<string, array<string, mixed>> $bundled */
        $bundled = is_array(config('studio_rendering.fonts.bundled', []))
            ? config('studio_rendering.fonts.bundled', [])
            : [];
        foreach ($bundled as $slug => $meta) {
            if (! is_array($meta)) {
                continue;
            }
            $p = trim((string) ($meta['path'] ?? ''));
            if ($p === '' || ! is_file($p) || ! is_readable($p)) {
                $this->error('Missing or unreadable bundled font "'.$slug.'": '.$p);
                $errors++;
            }
        }
        if ($errors === 0) {
            $this->info('All bundled font files are present and readable.');
        }

        try {
            $def = StudioRenderingFontPaths::effectiveDefaultFontPath();
            if (is_file($def) && is_readable($def)) {
                $this->info('Default font resolves to: '.$def);
            } else {
                $this->error('Default font path is not readable: '.$def);
                $errors++;
            }
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            $errors++;
        }

        $tenantRoot = (new \App\Studio\Rendering\StudioRenderingFontFileCache)->fontCacheRoot();
        File::ensureDirectoryExists($tenantRoot);
        if (! is_writable($tenantRoot)) {
            $this->error('Tenant font cache directory is not writable: '.$tenantRoot);
            $errors++;
        } else {
            $this->info('Tenant font cache writable: '.$tenantRoot);
        }

        $googleRoot = (new \App\Studio\Rendering\StudioGoogleFontFileCache)->googleCacheRoot();
        File::ensureDirectoryExists($googleRoot);
        if (! is_writable($googleRoot)) {
            $this->error('Google font cache directory is not writable: '.$googleRoot);
            $errors++;
        } else {
            $this->info('Google font cache writable: '.$googleRoot);
        }

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
