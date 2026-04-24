<?php

namespace App\Studio\Rendering;

use App\Studio\Rendering\Dto\RenderLayer;
use Illuminate\Support\Str;

/**
 * Rasterizes fill / simple shape layers to transparent PNGs for FFmpeg overlay (Imagick).
 */
final class FillShapeOverlayRasterizer
{
    /**
     * @return string absolute path to PNG in workspace
     */
    public function rasterizeToPath(RenderLayer $layer, string $workspacePath): string
    {
        $spec = $layer->extra['fill_shape_spec'] ?? null;
        if (! is_array($spec)) {
            throw new \RuntimeException('fill_shape_spec missing for layer '.$layer->id);
        }
        if (! class_exists(\Imagick::class)) {
            throw new \RuntimeException('Fill/shape rasterization requires Imagick (layer '.$layer->id.').');
        }

        $w = max(1, $layer->width);
        $h = max(1, $layer->height);
        $kind = (string) ($spec['kind'] ?? 'fill_solid');
        $img = new \Imagick;
        $img->newImage($w, $h, new \ImagickPixel('transparent'));
        $img->setImageFormat('png32');

        if ($kind === 'fill_gradient_linear') {
            $this->drawLinearGradient($img, $w, $h, $spec);
        } elseif ($kind === 'fill_radial_text_boost') {
            $this->drawRadialTextBoost($img, $w, $h, $spec);
        } elseif ($kind === 'shape_ellipse') {
            $this->drawEllipse($img, $w, $h, $spec);
        } elseif ($kind === 'fill_solid' || $kind === 'shape_rect') {
            $this->drawRoundedRect($img, $w, $h, $spec);
        } else {
            throw new \RuntimeException('Unsupported fill_shape_spec.kind '.$kind.' for layer '.$layer->id);
        }

        $blob = $img->getImageBlob();
        $img->clear();
        $img->destroy();
        $path = $workspacePath.DIRECTORY_SEPARATOR.'fillshape_'.$layer->id.'_'.Str::random(4).'.png';
        file_put_contents($path, $blob);

        return $path;
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function drawLinearGradient(\Imagick $img, int $w, int $h, array $spec): void
    {
        $h1 = $this->gradientEndpointForPseudo((string) ($spec['color_start'] ?? 'transparent'));
        $h2 = $this->gradientEndpointForPseudo((string) ($spec['color_end'] ?? '#000000'));
        $grad = new \Imagick;
        $grad->newPseudoImage($w, $h, 'gradient:'.$h1.'-'.$h2);
        $grad->setImageFormat('png32');
        $img->compositeImage($grad, \Imagick::COMPOSITE_OVER, 0, 0);
        $grad->clear();
        $grad->destroy();
    }

    /**
     * Matches editor {@code textBoostFillBackgroundCss} radial: center (secondary or transparent) → edge (primary × opacity).
     * Uses ImageMagick {@code radial-gradient:} when available; otherwise a vertical linear gradient with the same RGBA endpoints.
     *
     * @param  array<string, mixed>  $spec
     */
    private function drawRadialTextBoost(\Imagick $img, int $w, int $h, array $spec): void
    {
        $opacity = max(0.0, min(1.0, (float) ($spec['opacity'] ?? 0.7)));
        $centerRaw = trim((string) ($spec['color_center_hex'] ?? 'transparent'));
        $edgeHex = trim((string) ($spec['color_edge_hex'] ?? '#000000'));
        if ($edgeHex === '') {
            $edgeHex = '#000000';
        }
        $edgeRgb = $this->parseRgbTriplet($edgeHex);
        $outerPseudo = $this->hexRgbToRgbaHash($edgeRgb, $opacity);

        if ($centerRaw === '' || strcasecmp($centerRaw, 'transparent') === 0) {
            $innerPseudo = '#00000000';
        } else {
            $innerRgb = $this->parseRgbTriplet($centerRaw);
            $innerPseudo = $this->hexRgbToRgbaHash($innerRgb, $opacity);
        }

        $grad = new \Imagick;
        try {
            $grad->newPseudoImage($w, $h, 'radial-gradient:'.$innerPseudo.'-'.$outerPseudo);
            $grad->setImageFormat('png32');
            $img->compositeImage($grad, \Imagick::COMPOSITE_OVER, 0, 0);
        } catch (\Throwable) {
            $grad->clear();
            $grad->destroy();
            $grad = new \Imagick;
            $grad->newPseudoImage($w, $h, 'gradient:'.$innerPseudo.'-'.$outerPseudo);
            $grad->setImageFormat('png32');
            $img->compositeImage($grad, \Imagick::COMPOSITE_OVER, 0, 0);
        }
        $grad->clear();
        $grad->destroy();
    }

    /**
     * @param  array{0:int,1:int,2:int}  $rgb
     */
    private function hexRgbToRgbaHash(array $rgb, float $opacity): string
    {
        $a = (int) round(max(0.0, min(1.0, $opacity)) * 255);

        return sprintf('#%02x%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2], $a);
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function drawRoundedRect(\Imagick $img, int $w, int $h, array $spec): void
    {
        $fill = $this->pixelFromCss((string) ($spec['fill'] ?? '#ffffff'));
        $radius = max(0.0, (float) ($spec['border_radius'] ?? 0));
        $draw = new \ImagickDraw;
        $draw->setFillColor($fill);
        if ($radius > 0.5) {
            $r = min($radius, min($w, $h) / 2 - 0.1);
            $draw->roundRectangle(0, 0, $w - 1, $h - 1, $r, $r);
        } else {
            $draw->rectangle(0, 0, $w - 1, $h - 1);
        }
        $img->drawImage($draw);
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function drawEllipse(\Imagick $img, int $w, int $h, array $spec): void
    {
        $fill = $this->pixelFromCss((string) ($spec['fill'] ?? '#ffffff'));
        $draw = new \ImagickDraw;
        $draw->setFillColor($fill);
        $rx = max(1.0, ($w - 1) / 2);
        $ry = max(1.0, ($h - 1) / 2);
        $draw->roundRectangle(0, 0, $w - 1, $h - 1, $rx, $ry);
        $img->drawImage($draw);
    }

    private function pixelFromCss(string $css): \ImagickPixel
    {
        $t = $this->parseRgbTriplet($css);

        return new \ImagickPixel(sprintf('rgb(%d,%d,%d)', $t[0], $t[1], $t[2]));
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private function gradientEndpointForPseudo(string $css): string
    {
        $s = trim($css);
        if ($s === '' || strcasecmp($s, 'transparent') === 0) {
            return '#00000000';
        }
        $t = $this->parseRgbTriplet($s);

        return sprintf('#%02x%02x%02xff', $t[0], $t[1], $t[2]);
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private function parseRgbTriplet(string $css): array
    {
        $s = trim($css);
        if (preg_match('/^#([0-9a-f]{6})$/i', $s, $m)) {
            $h = $m[1];

            return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
        }
        if (preg_match('/^#([0-9a-f]{3})$/i', $s, $m)) {
            $x = $m[1];

            return [hexdec($x[0].$x[0]), hexdec($x[1].$x[1]), hexdec($x[2].$x[2])];
        }
        if (preg_match('/rgba?\(\s*([0-9]+)\s*,\s*([0-9]+)\s*,\s*([0-9]+)/i', $s, $m)) {
            return [
                max(0, min(255, (int) $m[1])),
                max(0, min(255, (int) $m[2])),
                max(0, min(255, (int) $m[3])),
            ];
        }

        return [0, 0, 0];
    }
}
