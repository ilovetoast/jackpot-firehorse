<?php

namespace App\Services;

use App\Models\Asset;
use Illuminate\Support\Facades\Log;

/**
 * Template-based compositing for enhanced preview thumbnails (GD).
 *
 * When {@see config('enhanced_preview.transparent_plate')} is true, the canvas stays
 * transparent (no gradient plate, no offset shadow rectangle) so Studio crops can sit
 * on CSS presentation surfaces; alpha is preserved into WebP/PNG output.
 */
final class TemplateRenderer
{
    /**
     * Map asset to template id (document → catalog, product-like → surface, else neutral).
     */
    public function selectTemplateForAsset(Asset $asset): string
    {
        $mime = strtolower((string) ($asset->mime_type ?? ''));
        if (str_contains($mime, 'pdf')
            || str_contains($mime, 'word')
            || str_contains($mime, 'sheet')
            || str_contains($mime, 'presentation')
            || str_contains($mime, 'msword')
            || str_contains($mime, 'officedocument')
        ) {
            return 'catalog_v1';
        }

        $slug = strtolower((string) ($asset->category?->slug ?? ''));
        if ($slug !== '' && (str_contains($slug, 'product') || str_contains($slug, 'packaging') || str_contains($slug, 'sku'))) {
            return 'surface_v1';
        }
        if ($slug !== '' && (str_contains($slug, 'doc') || str_contains($slug, 'catalog') || str_contains($slug, 'brief'))) {
            return 'catalog_v1';
        }

        return 'neutral_v1';
    }

    /**
     * Composite source raster into a styled canvas for one thumbnail style.
     *
     * @param  array<string, mixed>  $styleConfig  Entry from assets.thumbnail_styles
     * @return string|null Absolute path to temp file (webp or jpg), or null on failure
     */
    public function renderCompositedThumbnail(string $sourcePath, string $templateId, string $styleName, array $styleConfig): ?string
    {
        if (! is_file($sourcePath) || filesize($sourcePath) === 0) {
            return null;
        }

        $templates = config('enhanced_preview.templates', []);
        $tpl = is_array($templates[$templateId] ?? null) ? $templates[$templateId] : config('enhanced_preview.templates.neutral_v1', []);

        $w = max(32, (int) ($styleConfig['width'] ?? 400));
        $h = max(32, (int) ($styleConfig['height'] ?? 400));

        $canvas = @imagecreatetruecolor($w, $h);
        if ($canvas === false) {
            return null;
        }

        $transparentPlate = (bool) config('enhanced_preview.transparent_plate', true);

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        if ($transparentPlate) {
            $clear = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefill($canvas, 0, 0, $clear);
        } else {
            imagealphablending($canvas, true);
            $this->fillGradientBackground(
                $canvas,
                $w,
                $h,
                array_map('intval', $tpl['bg_top'] ?? [248, 248, 248]),
                array_map('intval', $tpl['bg_bottom'] ?? [232, 232, 232])
            );
        }
        imagealphablending($canvas, true);

        $blob = @file_get_contents($sourcePath);
        if ($blob === false || $blob === '') {
            imagedestroy($canvas);

            return null;
        }

        $src = @imagecreatefromstring($blob);
        if ($src === false) {
            imagedestroy($canvas);
            Log::info('[TemplateRenderer] imagecreatefromstring failed', [
                'template' => $templateId,
                'style' => $styleName,
            ]);

            return null;
        }

        $sw = imagesx($src);
        $sh = imagesy($src);
        if ($sw < 1 || $sh < 1) {
            imagedestroy($src);
            imagedestroy($canvas);

            return null;
        }

        $padRatio = (float) ($tpl['padding_ratio'] ?? 0.08);
        // Transparent plate: no inset margin in the raster — avoids a faint “frame” in Presentation
        // (letterboxed transparency + CSS ring/box-shadow read as a box around the art).
        $pad = $transparentPlate
            ? 0
            : (int) round(min($w, $h) * max(0.02, min(0.25, $padRatio)));
        $off = $transparentPlate ? 0 : (int) ($tpl['shadow_offset'] ?? 4);
        $cw = max(1, $w - 2 * $pad - $off);
        $ch = max(1, $h - 2 * $pad - $off);

        $scale = min($cw / $sw, $ch / $sh);
        $tw = max(1, (int) round($sw * $scale));
        $th = max(1, (int) round($sh * $scale));

        if (! imageistruecolor($src) && function_exists('imagepalettetotruecolor')) {
            @imagepalettetotruecolor($src);
        }

        $resized = $transparentPlate
            ? $this->resizeTruecolorPreservingAlpha($src, $tw, $th)
            : imagescale($src, $tw, $th);
        imagedestroy($src);
        if ($resized === false) {
            imagedestroy($canvas);

            return null;
        }

        $dx = $pad + (int) (($cw - $tw) / 2);
        $dy = $pad + (int) (($ch - $th) / 2);

        if (! $transparentPlate) {
            $shadowAlpha = (int) ($tpl['shadow_alpha'] ?? 55);
            $shadowAlpha = max(0, min(127, $shadowAlpha));
            $shadowCol = imagecolorallocatealpha($canvas, 0, 0, 0, $shadowAlpha);
            imagefilledrectangle(
                $canvas,
                $dx + $off,
                $dy + $off,
                $dx + $tw + $off,
                $dy + $th + $off,
                $shadowCol
            );
        }

        imagesavealpha($resized, true);
        imagealphablending($resized, true);
        imagealphablending($canvas, true);
        imagecopy($canvas, $resized, $dx, $dy, 0, 0, $tw, $th);
        imagedestroy($resized);

        $quality = (int) ($styleConfig['quality'] ?? 88);
        $quality = max(40, min(100, $quality));

        $tmp = tempnam(sys_get_temp_dir(), 'enh_tpl_');
        if ($tmp === false) {
            imagedestroy($canvas);

            return null;
        }

        $ok = false;
        $outPath = '';

        if ($transparentPlate) {
            imagesavealpha($canvas, true);
            imagealphablending($canvas, false);
            $outPath = $tmp.'.webp';
            @unlink($tmp);
            if (function_exists('imagewebp')) {
                $ok = @imagewebp($canvas, $outPath, $quality);
            }
            if (! $ok) {
                if (is_file($outPath)) {
                    @unlink($outPath);
                }
                $outPath = $tmp.'.png';
                $ok = @imagepng($canvas, $outPath, 6);
            }
        } else {
            $format = config('assets.thumbnail.output_format', 'webp') === 'webp' ? 'webp' : 'jpeg';
            $ext = $format === 'webp' ? '.webp' : '.jpg';
            $outPath = $tmp.$ext;
            @unlink($tmp);
            if ($format === 'webp' && function_exists('imagewebp')) {
                $ok = @imagewebp($canvas, $outPath, $quality);
            }
            if (! $ok) {
                $outPath = $tmp.'.jpg';
                $ok = @imagejpeg($canvas, $outPath, $quality);
            }
        }

        imagedestroy($canvas);

        return $ok ? $outPath : null;
    }

    /**
     * @param  array{0:int,1:int,2:int}  $topRgb
     * @param  array{0:int,1:int,2:int}  $bottomRgb
     */
    /**
     * @param  \GdImage|resource  $canvas
     */
    protected function fillGradientBackground($canvas, int $w, int $h, array $topRgb, array $bottomRgb): void
    {
        $h = max(1, $h);
        for ($y = 0; $y < $h; $y++) {
            $t = $h <= 1 ? 0.0 : $y / ($h - 1);
            $r = (int) round($topRgb[0] * (1 - $t) + $bottomRgb[0] * $t);
            $g = (int) round($topRgb[1] * (1 - $t) + $bottomRgb[1] * $t);
            $b = (int) round($topRgb[2] * (1 - $t) + $bottomRgb[2] * $t);
            $col = imagecolorallocate($canvas, max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)));
            imageline($canvas, 0, $y, $w, $y, $col);
        }
    }

    /**
     * Resize with explicit transparent margins — {@see imagescale()} can flatten alpha on some GD builds.
     *
     * @param  \GdImage|resource  $src
     * @return \GdImage|resource|false
     */
    protected function resizeTruecolorPreservingAlpha($src, int $tw, int $th)
    {
        $sw = imagesx($src);
        $sh = imagesy($src);
        if ($sw < 1 || $sh < 1 || $tw < 1 || $th < 1) {
            return false;
        }

        $dst = imagecreatetruecolor($tw, $th);
        if ($dst === false) {
            return false;
        }

        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefill($dst, 0, 0, $transparent);
        imagealphablending($dst, true);
        imagesavealpha($src, true);
        imagealphablending($src, true);

        $ok = @imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $sw, $sh);

        return $ok ? $dst : false;
    }
}
