<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Verify SVG Thumbnail Support Command
 *
 * Mirror of pdf:verify. Confirms that a host has the full SVG rasterization
 * toolchain used by ThumbnailGenerationService::renderSvgViaRsvg() and
 * generateSvgRasterizedThumbnail(): rsvg-convert binary (from librsvg2-bin),
 * PHP Imagick extension with SVG coders, and a working round-trip.
 *
 * Run on every new or retrofitted staging/production host before flipping
 * traffic. If this fails, SVG uploads will never produce thumbnails.
 *
 * Usage:
 *   php artisan svg:verify
 *   php artisan svg:verify --svg=/path/to/test.svg
 */
class VerifySvgThumbnailSupport extends Command
{
    protected $signature = 'svg:verify
                            {--svg= : Path to a test SVG file (optional; default uses public/jp-wordmark-inverted.svg)}';

    protected $description = 'Verify SVG thumbnail generation support (rsvg-convert, PHP Imagick SVG, end-to-end rasterization)';

    public function handle(): int
    {
        $this->info('Verifying SVG thumbnail generation support...');
        $this->newLine();

        $this->info('1. Checking rsvg-convert binary (librsvg2-bin)...');
        $rsvgPath = trim((string) shell_exec('command -v rsvg-convert 2>/dev/null'));
        if ($rsvgPath === '') {
            $this->error('   ❌ rsvg-convert not found on PATH.');
            $this->line('   Install: sudo apt-get install -y librsvg2-bin');

            return Command::FAILURE;
        }
        $version = trim((string) shell_exec('rsvg-convert --version 2>&1'));
        $this->info("   ✅ rsvg-convert available at {$rsvgPath}");
        $this->line("   {$version}");

        $this->newLine();
        $this->info('2. Checking PHP Imagick extension...');
        if (! extension_loaded('imagick')) {
            $this->error('   ❌ PHP Imagick extension is not loaded.');

            return Command::FAILURE;
        }
        $imagick = new \Imagick;
        $imagickVersion = $imagick->getVersion();
        $this->info("   ✅ Imagick loaded: {$imagickVersion['versionString']}");

        $svgFormats = array_values(array_filter(
            \Imagick::queryFormats(),
            fn ($f) => stripos($f, 'svg') !== false
        ));
        if ($svgFormats === []) {
            $this->warn('   ⚠️  Imagick has no SVG coders registered. Rasterization will still work via rsvg-convert, but Imagick cannot open raw .svg directly.');
        } else {
            $this->info('   ✅ Imagick SVG coders: '.implode(', ', $svgFormats));
        }

        $this->newLine();
        $this->info('3. Testing end-to-end SVG rasterization (rsvg-convert → PNG → Imagick → WebP)...');

        $sourcePath = $this->option('svg');
        $createdTestFile = false;
        if (! $sourcePath) {
            $candidate = base_path('public/jp-wordmark-inverted.svg');
            if (is_file($candidate)) {
                $sourcePath = $candidate;
            }
        }
        if (! $sourcePath || ! is_file($sourcePath)) {
            $sourcePath = storage_path('app/svg-verify-sample.svg');
            file_put_contents($sourcePath, <<<'SVG'
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 100">
  <rect width="200" height="100" fill="#7F3FBF"/>
  <text x="100" y="60" text-anchor="middle" fill="white" font-family="Helvetica" font-size="32">SVG OK</text>
</svg>
SVG);
            $createdTestFile = true;
        }
        $this->line("   source: {$sourcePath}");

        $tmpPng = tempnam(sys_get_temp_dir(), 'svgverify_').'.png';
        $cmd = sprintf(
            'rsvg-convert -w 512 %s -o %s 2>&1',
            escapeshellarg($sourcePath),
            escapeshellarg($tmpPng)
        );
        exec($cmd, $out, $exit);
        if ($exit !== 0 || ! is_file($tmpPng) || filesize($tmpPng) === 0) {
            $this->error(sprintf(
                '   ❌ rsvg-convert failed (exit %d): %s',
                $exit,
                trim(implode("\n", $out)) ?: 'no output'
            ));
            $createdTestFile && @unlink($sourcePath);

            return Command::FAILURE;
        }
        $info = getimagesize($tmpPng);
        $this->info(sprintf(
            '   ✅ rsvg-convert produced %dx%d PNG (%d bytes)',
            $info[0] ?? 0,
            $info[1] ?? 0,
            filesize($tmpPng)
        ));

        try {
            $im = new \Imagick($tmpPng);
            $im->setImageFormat('webp');
            $im->setImageCompressionQuality(92);
            $im->stripImage();
            $tmpWebp = tempnam(sys_get_temp_dir(), 'svgverify_').'.webp';
            $im->writeImage($tmpWebp);
            $im->clear();
            $im->destroy();

            if (! is_file($tmpWebp) || filesize($tmpWebp) === 0) {
                throw new \RuntimeException('Imagick produced empty WebP output');
            }
            $this->info(sprintf(
                '   ✅ Imagick WebP encode succeeded (%d bytes)',
                filesize($tmpWebp)
            ));
            @unlink($tmpWebp);
        } catch (\Throwable $e) {
            $this->error("   ❌ Imagick WebP encode failed: {$e->getMessage()}");
            @unlink($tmpPng);
            $createdTestFile && @unlink($sourcePath);

            return Command::FAILURE;
        }

        @unlink($tmpPng);
        $createdTestFile && @unlink($sourcePath);

        $this->newLine();
        $this->info('✅ All checks passed. SVG thumbnail generation is ready on this host.');

        return Command::SUCCESS;
    }
}
