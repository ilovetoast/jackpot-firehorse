<?php

namespace App\Console\Commands;

use App\Studio\Rendering\StudioGoogleFontFileCache;
use App\Studio\Rendering\StudioRenderingFontPaths;
use Illuminate\Console\Command;

class StudioFontsWarmCommand extends Command
{
    protected $signature = 'studio:fonts:warm';

    protected $description = 'Pre-download curated Google fonts and verify bundled fonts for Studio native export';

    public function handle(StudioGoogleFontFileCache $googleCache): int
    {
        $n = 0;
        /** @var array<string, array<string, mixed>> $google */
        $google = is_array(config('studio_rendering.fonts.google', []))
            ? config('studio_rendering.fonts.google', [])
            : [];
        foreach ($google as $slug => $meta) {
            if (! is_array($meta)) {
                continue;
            }
            $url = trim((string) ($meta['download_url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $path = $googleCache->materializeFromRegistrySlug((string) $slug, $url);
            $this->info('Warmed google:'.$slug.' → '.$path);
            $n++;
        }

        $def = StudioRenderingFontPaths::effectiveDefaultFontPath();
        $this->info('Default font: '.$def);

        $this->info('Warmed '.$n.' curated Google font(s).');

        return self::SUCCESS;
    }
}
