<?php

namespace App\Studio\Animation\Rendering;

use App\Models\Asset;
use App\Models\StudioAnimationJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Imagick;
use ImagickDraw;
use ImagickPixel;

/**
 * Best-effort server-side PNG rasterization from locked editor document JSON.
 * Falls back to client snapshot when the document uses unsupported layer types or rasterization fails.
 */
final class LockedDocumentServerFrameRenderer
{
    public function tryRenderPng(StudioAnimationJob $job, array $document): LockedDocumentServerFrameResult
    {
        if (! (bool) config('studio_animation.server_locked_frame.enabled', true)) {
            return LockedDocumentServerFrameResult::skipped('server_locked_frame_disabled');
        }

        if (! extension_loaded('imagick')) {
            return LockedDocumentServerFrameResult::skipped('imagick_extension_missing');
        }

        $w = (int) ($document['width'] ?? 0);
        $h = (int) ($document['height'] ?? 0);
        if ($w < 1 || $h < 1 || $w > 8192 || $h > 8192) {
            return LockedDocumentServerFrameResult::skipped('invalid_canvas_dimensions');
        }

        $layers = $document['layers'] ?? null;
        if (! is_array($layers)) {
            return LockedDocumentServerFrameResult::skipped('missing_layers');
        }

        foreach ($layers as $layer) {
            if (! is_array($layer)) {
                continue;
            }
            if (($layer['visible'] ?? true) !== true) {
                continue;
            }
            $type = (string) ($layer['type'] ?? '');
            if (! in_array($type, ['fill', 'image', 'text'], true)) {
                return LockedDocumentServerFrameResult::skipped('unsupported_layer_type:'.$type);
            }
        }

        try {
            $canvas = new Imagick;
            $canvas->newImage($w, $h, new ImagickPixel('#ffffff'), 'png');
            $canvas->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);

            $sorted = $layers;
            usort($sorted, static function (mixed $a, mixed $b): int {
                $za = is_array($a) ? (int) ($a['z'] ?? 0) : 0;
                $zb = is_array($b) ? (int) ($b['z'] ?? 0) : 0;

                return $za <=> $zb;
            });

            foreach ($sorted as $layer) {
                if (! is_array($layer) || ($layer['visible'] ?? true) !== true) {
                    continue;
                }
                $type = (string) ($layer['type'] ?? '');
                if ($type === 'fill') {
                    $this->drawFillLayer($canvas, $layer, $w, $h);
                } elseif ($type === 'image') {
                    $this->drawImageLayer($canvas, $layer, $job);
                } elseif ($type === 'text') {
                    $this->drawTextLayer($canvas, $layer);
                }
            }

            $canvas->setImageFormat('png');
            $binary = $canvas->getImageBlob();
            $canvas->clear();
            $canvas->destroy();

            if ($binary === '' || strlen($binary) < 32) {
                return LockedDocumentServerFrameResult::skipped('empty_raster_output');
            }

            return LockedDocumentServerFrameResult::success($binary, [
                'layer_count' => count($layers),
                'imagick' => true,
            ]);
        } catch (\Throwable $e) {
            Log::info('[LockedDocumentServerFrameRenderer] render failed', [
                'job_id' => $job->id,
                'error' => $e->getMessage(),
            ]);

            return LockedDocumentServerFrameResult::skipped('render_exception:'.$e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $layer
     */
    private function drawFillLayer(Imagick $canvas, array $layer, int $canvasW, int $canvasH): void
    {
        $t = $layer['transform'] ?? [];
        if (! is_array($t)) {
            return;
        }
        $x = (float) ($t['x'] ?? 0);
        $y = (float) ($t['y'] ?? 0);
        $lw = (float) ($t['width'] ?? 0);
        $lh = (float) ($t['height'] ?? 0);
        if ($lw <= 0 || $lh <= 0) {
            return;
        }

        $fillKind = (string) ($layer['fillKind'] ?? 'solid');

        if ($fillKind === 'gradient') {
            $c1 = $this->normalizeCssColor((string) ($layer['gradientStartColor'] ?? $layer['color'] ?? '#000000'));
            $c2 = $this->normalizeCssColor((string) ($layer['gradientEndColor'] ?? $layer['color'] ?? '#000000'));
            $grad = new Imagick;
            $grad->newPseudoImage((int) max(1, $lw), (int) max(1, $lh), 'gradient:'.$c1.'-'.$c2);
            $canvas->compositeImage($grad, Imagick::COMPOSITE_OVER, (int) $x, (int) $y);
            $grad->clear();
            $grad->destroy();

            return;
        }

        $color = $this->normalizeCssColor((string) ($layer['color'] ?? '#cccccc'));
        $draw = new ImagickDraw;
        $draw->setFillColor($color);
        $draw->rectangle($x, $y, $x + $lw, $y + $lh);
        $canvas->drawImage($draw);
    }

    /**
     * @param  array<string, mixed>  $layer
     */
    private function drawImageLayer(Imagick $canvas, array $layer, StudioAnimationJob $job): void
    {
        $assetId = $layer['assetId'] ?? null;
        if (! is_string($assetId) || $assetId === '') {
            throw new \RuntimeException('image_layer_missing_asset_id');
        }

        $asset = Asset::query()
            ->whereKey($assetId)
            ->where('tenant_id', $job->tenant_id)
            ->where('brand_id', $job->brand_id)
            ->first();
        if (! $asset) {
            throw new \RuntimeException('image_layer_asset_not_found');
        }

        $path = $asset->storage_root_path;
        if (! is_string($path) || $path === '') {
            $asset->loadMissing('currentVersion');
            $path = (string) ($asset->currentVersion?->file_path ?? '');
        }
        if ($path === '') {
            throw new \RuntimeException('image_layer_asset_missing_path');
        }

        $bytes = null;
        foreach (['s3', 'local', 'public'] as $tryDisk) {
            if (Storage::disk($tryDisk)->exists($path)) {
                $bytes = Storage::disk($tryDisk)->get($path);
                break;
            }
        }
        if (! is_string($bytes) || $bytes === '') {
            throw new \RuntimeException('image_layer_file_missing_on_disk');
        }

        $overlay = new Imagick;
        $overlay->readImageBlob($bytes);
        $t = $layer['transform'] ?? [];
        if (! is_array($t)) {
            $overlay->clear();
            $overlay->destroy();

            return;
        }
        $x = (int) ($t['x'] ?? 0);
        $y = (int) ($t['y'] ?? 0);
        $tw = (int) ($t['width'] ?? 0);
        $th = (int) ($t['height'] ?? 0);
        if ($tw < 1 || $th < 1) {
            $overlay->clear();
            $overlay->destroy();

            return;
        }
        $overlay->resizeImage($tw, $th, Imagick::FILTER_LANCZOS, 1, true);
        $canvas->compositeImage($overlay, Imagick::COMPOSITE_OVER, $x, $y);
        $overlay->clear();
        $overlay->destroy();
    }

    /**
     * @param  array<string, mixed>  $layer
     */
    private function drawTextLayer(Imagick $canvas, array $layer): void
    {
        $fontPath = (string) config('studio_animation.server_locked_frame.font_path', '');
        if ($fontPath === '' || ! is_readable($fontPath)) {
            $candidates = [
                '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
                '/usr/share/fonts/TTF/DejaVuSans.ttf',
            ];
            foreach ($candidates as $c) {
                if (is_readable($c)) {
                    $fontPath = $c;
                    break;
                }
            }
        }
        if ($fontPath === '' || ! is_readable($fontPath)) {
            throw new \RuntimeException('text_layer_font_unavailable');
        }

        $t = $layer['transform'] ?? [];
        if (! is_array($t)) {
            return;
        }
        $x = (float) ($t['x'] ?? 0);
        $y = (float) ($t['y'] ?? 0);
        $content = (string) ($layer['content'] ?? '');
        if ($content === '') {
            return;
        }
        $style = is_array($layer['style'] ?? null) ? $layer['style'] : [];
        $size = max(6, min(256, (int) ($style['fontSize'] ?? 16)));
        $color = $this->normalizeCssColor((string) ($style['color'] ?? '#000000'));

        $draw = new ImagickDraw;
        $draw->setFont($fontPath);
        $draw->setFontSize($size);
        $draw->setFillColor($color);
        $draw->setTextAntialias(true);

        $lines = explode("\n", $content);
        $lineHeight = $size * 1.2;
        $yy = $y + $size;
        foreach ($lines as $line) {
            $canvas->annotateImage($draw, (int) $x, (int) $yy, 0, $line);
            $yy += $lineHeight;
        }
    }

    private function normalizeCssColor(string $css): string
    {
        $css = trim($css);
        if ($css === '') {
            return '#000000';
        }
        if (str_starts_with($css, '#')) {
            return strlen($css) >= 4 ? $css : '#000000';
        }
        if (str_starts_with($css, 'rgb')) {
            return '#888888';
        }

        return '#000000';
    }
}
