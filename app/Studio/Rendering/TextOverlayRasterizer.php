<?php

namespace App\Studio\Rendering;

use App\Models\Tenant;
use App\Studio\Rendering\Dto\RenderLayer;
use App\Studio\Rendering\Dto\ResolvedStudioFont;
use App\Studio\Rendering\Exceptions\StudioFontResolutionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Renders Studio text layers to transparent PNG tiles for FFmpeg overlay.
 * Uses Imagick when available, otherwise GD + FreeType.
 *
 * Fonts must always be absolute local paths (see {@see StudioRenderingFontResolver}).
 */
final class TextOverlayRasterizer
{
    public function __construct(
        private StudioRenderingFontResolver $fonts,
    ) {}

    /**
     * @param  array<string, mixed>|null  $exportRasterMeta  When non-null, filled with font path/source for export diagnostics
     * @return string absolute path to PNG
     */
    public function rasterizeToPath(
        RenderLayer $layer,
        string $workspacePath,
        Tenant $tenant,
        ?int $compositionBrandId,
        ?array &$exportRasterMeta = null,
    ): string {
        $w = max(1, $layer->width);
        $h = max(1, $layer->height);
        $content = (string) ($layer->extra['content'] ?? '');
        $fontFamily = (string) ($layer->extra['font_family'] ?? '');

        $resolved = $this->fonts->resolveForTextLayer($tenant, $compositionBrandId, $layer->extra, $fontFamily, $layer->id);

        $fontPath = $resolved->absolutePath;
        $this->assertRasterizerLocalFont($fontPath, $layer->id, $resolved);

        $fontSize = (int) ($layer->extra['font_size'] ?? 24);
        $color = (string) ($layer->extra['color'] ?? '#ffffff');
        $lineHeight = (float) ($layer->extra['line_height'] ?? 1.25);
        $align = (string) ($layer->extra['text_align'] ?? 'left');
        if (! in_array($align, ['left', 'center', 'right'], true)) {
            $align = 'left';
        }

        $fontDebug = array_merge($resolved->debug, [
            'resolved_font_source' => $resolved->source,
            'resolved_font_path' => $fontPath,
            'had_explicit_custom_font' => $resolved->hadExplicitCustomFontSelection,
        ]);

        $cacheDir = $this->cacheDirectory();
        $spec = [
            'w' => $w,
            'h' => $h,
            't' => $content,
            'fp' => $fontPath,
            'fs' => $fontSize,
            'c' => $color,
            'lh' => $lineHeight,
            'a' => $align,
            'font_debug' => $fontDebug,
        ];
        $hash = hash('sha256', json_encode($spec, JSON_THROW_ON_ERROR));
        $cached = $cacheDir.DIRECTORY_SEPARATOR.$hash.'.png';
        if (is_file($cached) && filesize($cached) > 32) {
            $copy = $workspacePath.DIRECTORY_SEPARATOR.'text_'.$layer->id.'_'.Str::random(4).'.png';
            File::copy($cached, $copy);
            if ($exportRasterMeta !== null) {
                $exportRasterMeta = array_merge(['layer_id' => $layer->id, 'png_path' => $copy], $fontDebug);
            }

            return $copy;
        }

        try {
            if (class_exists(\Imagick::class)) {
                $png = $this->rasterizeImagick($w, $h, $content, $fontPath, $fontSize, $color, $lineHeight, $align);
            } elseif (function_exists('imagecreatetruecolor') && function_exists('imagettftext')) {
                $png = $this->rasterizeGd($w, $h, $content, $fontPath, $fontSize, $color, $lineHeight, $align);
            } else {
                throw new StudioFontResolutionException(
                    'rasterizer_missing_imagick_and_gd',
                    'Text rasterization requires Imagick PHP extension or GD with FreeType.',
                    ['layer_id' => $layer->id],
                );
            }
        } catch (StudioFontResolutionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new StudioFontResolutionException(
                'rasterizer_engine_failed',
                'Imagick/GD could not render text with the resolved font: '.$e->getMessage(),
                ['layer_id' => $layer->id, 'font_path' => $fontPath],
                $e,
            );
        }

        File::ensureDirectoryExists($cacheDir);
        file_put_contents($cached, $png);
        $copy = $workspacePath.DIRECTORY_SEPARATOR.'text_'.$layer->id.'_'.Str::random(4).'.png';
        file_put_contents($copy, $png);
        if ($exportRasterMeta !== null) {
            $exportRasterMeta = array_merge(['layer_id' => $layer->id, 'png_path' => $copy], $fontDebug);
        }

        return $copy;
    }

    private function assertRasterizerLocalFont(string $path, string $layerId, ResolvedStudioFont $resolved): void
    {
        if (! str_starts_with($path, '/') && ! (strlen($path) > 2 && ctype_alpha($path[0]) && $path[1] === ':')) {
            throw new StudioFontResolutionException(
                'font_path_not_absolute',
                'Rasterizer received a non-absolute font path.',
                ['layer_id' => $layerId, 'path' => $path, 'source' => $resolved->source],
            );
        }
        if (! is_file($path) || ! is_readable($path)) {
            throw new StudioFontResolutionException(
                'cached_font_not_readable',
                'Font path is missing or not readable for rasterization.',
                ['layer_id' => $layerId, 'path' => $path],
            );
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $raw = trim((string) config('studio_rendering.allowed_font_extensions', 'ttf,otf'));
        $allowed = array_filter(array_map('trim', explode(',', strtolower($raw))));
        $allowed = $allowed !== [] ? array_values($allowed) : ['ttf', 'otf'];
        if (! in_array($ext, $allowed, true)) {
            throw new StudioFontResolutionException(
                'unsupported_font_extension',
                'Rasterizer rejected font extension ".'.$ext.'".',
                ['layer_id' => $layerId, 'path' => $path],
            );
        }
    }

    private function cacheDirectory(): string
    {
        $sub = trim((string) config('studio_rendering.text_raster_cache_subdir', 'cache/studio-text-raster'), DIRECTORY_SEPARATOR.'/\\');

        return storage_path('app'.DIRECTORY_SEPARATOR.$sub);
    }

    private function rasterizeImagick(
        int $w,
        int $h,
        string $text,
        string $fontPath,
        int $fontSize,
        string $colorCss,
        float $lineHeight,
        string $align,
    ): string {
        $img = new \Imagick;
        $img->newImage($w, $h, new \ImagickPixel('transparent'));
        $img->setImageFormat('png32');
        $draw = new \ImagickDraw;
        $draw->setFont($fontPath);
        $draw->setFontSize((float) $fontSize);
        $draw->setFillColor($this->imagickPixelFromCss($colorCss));
        $draw->setTextAntialias(true);

        $lines = $this->wrapLines($text, $fontPath, $fontSize, $w - 8);
        $linePx = max((int) round($fontSize * $lineHeight), $fontSize + 2);
        $blockH = count($lines) * $linePx;
        $y0 = (int) max(4, round(($h - $blockH) / 2));

        $y = $y0;
        foreach ($lines as $line) {
            $metrics = $img->queryFontMetrics($draw, $line);
            $tw = (float) ($metrics['textWidth'] ?? 0);
            $x = match ($align) {
                'center' => ($w - $tw) / 2,
                'right' => $w - $tw - 4,
                default => 4.0,
            };
            $img->annotateImage($draw, (int) round($x), (int) round($y + $fontSize), 0, $line);
            $y += $linePx;
        }

        $blob = $img->getImageBlob();
        $img->clear();
        $img->destroy();

        return $blob;
    }

    /**
     * @return list<string>
     */
    private function wrapLines(string $text, string $fontPath, int $fontSize, int $maxWidth): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $paragraphs = explode("\n", $text);
        $out = [];
        foreach ($paragraphs as $para) {
            $words = preg_split('/\s+/', trim($para), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($words === []) {
                $out[] = '';

                continue;
            }
            $line = '';
            foreach ($words as $word) {
                $trial = $line === '' ? $word : $line.' '.$word;
                $bbox = @imagettfbbox((float) $fontSize, 0.0, $fontPath, $trial);
                $wpx = $bbox !== false ? abs(($bbox[2] ?? 0) - ($bbox[0] ?? 0)) : strlen($trial) * $fontSize * 0.6;
                if ($wpx > $maxWidth && $line !== '') {
                    $out[] = $line;
                    $line = $word;
                } else {
                    $line = $trial;
                }
            }
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out === [] ? [''] : $out;
    }

    private function rasterizeGd(
        int $w,
        int $h,
        string $text,
        string $fontPath,
        int $fontSize,
        string $colorCss,
        float $lineHeight,
        string $align,
    ): string {
        $im = imagecreatetruecolor($w, $h);
        if ($im === false) {
            throw new \RuntimeException('GD imagecreatetruecolor failed.');
        }
        imagesavealpha($im, true);
        imagealphablending($im, false);
        $transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
        imagefill($im, 0, 0, $transparent);
        $rgb = $this->parseRgb($colorCss);
        $solid = imagecolorallocatealpha($im, $rgb[0], $rgb[1], $rgb[2], 0);
        $lines = $this->wrapLines($text, $fontPath, $fontSize, $w - 8);
        $linePx = max((int) round($fontSize * $lineHeight), $fontSize + 2);
        $blockH = count($lines) * $linePx;
        $y0 = (int) max($fontSize, round(($h - $blockH) / 2 + $fontSize * 0.75));
        $y = $y0;
        foreach ($lines as $line) {
            $bbox = imagettfbbox((float) $fontSize, 0.0, $fontPath, $line);
            $tw = $bbox !== false ? abs(($bbox[2] ?? 0) - ($bbox[0] ?? 0)) : 0;
            $x = match ($align) {
                'center' => (int) round(($w - $tw) / 2),
                'right' => (int) max(4, $w - $tw - 4),
                default => 4,
            };
            imagettftext($im, (float) $fontSize, 0.0, (float) $x, (float) $y, $solid, $fontPath, $line);
            $y += $linePx;
        }
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        imagedestroy($im);

        return $png;
    }

    private function imagickPixelFromCss(string $css): \ImagickPixel
    {
        $rgb = $this->parseRgb($css);

        return new \ImagickPixel(sprintf('rgb(%d,%d,%d)', $rgb[0], $rgb[1], $rgb[2]));
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private function parseRgb(string $css): array
    {
        $s = trim($css);
        if (preg_match('/^#([0-9a-f]{6})$/i', $s, $m)) {
            $h = $m[1];

            return [
                hexdec(substr($h, 0, 2)),
                hexdec(substr($h, 2, 2)),
                hexdec(substr($h, 4, 2)),
            ];
        }
        if (preg_match('/^#([0-9a-f]{3})$/i', $s, $m)) {
            $x = $m[1];

            return [
                hexdec($x[0].$x[0]),
                hexdec($x[1].$x[1]),
                hexdec($x[2].$x[2]),
            ];
        }
        if (preg_match('/rgba?\(\s*([0-9]+)\s*,\s*([0-9]+)\s*,\s*([0-9]+)/i', $s, $m)) {
            return [
                max(0, min(255, (int) $m[1])),
                max(0, min(255, (int) $m[2])),
                max(0, min(255, (int) $m[3])),
            ];
        }

        return [255, 255, 255];
    }
}
