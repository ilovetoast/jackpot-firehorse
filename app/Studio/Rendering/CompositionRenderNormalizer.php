<?php

namespace App\Studio\Rendering;

use App\Models\Composition;
use App\Services\Studio\StudioCompositionVideoExportMediaHelper;
use App\Studio\Rendering\Dto\RenderLayer;
use App\Studio\Rendering\Dto\RenderTimeline;
use Illuminate\Support\Facades\Log;

/**
 * Maps persisted {@code document_json} overlay layers for FFmpeg-native export.
 * Primary/base video timing is applied outside (same trim rules as legacy).
 */
final class CompositionRenderNormalizer
{
    /**
     * @param  array<string, mixed>  $primaryVideoLayer
     * @return list<RenderLayer>
     */
    public function buildOverlayLayers(
        Composition $composition,
        array $primaryVideoLayer,
        RenderTimeline $timeline,
    ): array {
        $doc = is_array($composition->document_json) ? $composition->document_json : [];
        $layersRaw = is_array($doc['layers'] ?? null) ? $doc['layers'] : [];
        $videoZ = (int) ($primaryVideoLayer['z'] ?? 0);
        $primaryId = (string) ($primaryVideoLayer['id'] ?? '');
        $durationMs = max(1, $timeline->durationMs);

        $outLayers = [];
        foreach ($layersRaw as $ly) {
            if (! is_array($ly)) {
                continue;
            }
            if (($ly['visible'] ?? true) === false) {
                continue;
            }
            $type = (string) ($ly['type'] ?? '');
            if (! in_array($type, ['image', 'generative_image', 'text'], true)) {
                continue;
            }
            $z = (int) ($ly['z'] ?? 0);
            if ($z <= $videoZ) {
                continue;
            }

            $t = is_array($ly['transform'] ?? null) ? $ly['transform'] : [];
            $x = (int) round((float) ($t['x'] ?? 0));
            $y = (int) round((float) ($t['y'] ?? 0));
            $lw = max(1, (int) round((float) ($t['width'] ?? 1)));
            $lh = max(1, (int) round((float) ($t['height'] ?? 1)));
            $rot = (float) ($t['rotation'] ?? 0.0);

            $tl = is_array($ly['timeline'] ?? null) ? $ly['timeline'] : [];
            $startMs = max(0, (int) ($tl['start_ms'] ?? 0));
            $endMs = (int) ($tl['end_ms'] ?? $durationMs);
            if ($endMs <= $startMs) {
                $endMs = $durationMs;
            }
            $endMs = min($endMs, $durationMs);
            $trimInMs = max(0, (int) ($tl['trim_in_ms'] ?? 0));
            $trimOutMs = max(0, (int) ($tl['trim_out_ms'] ?? 0));
            $muted = (bool) ($tl['muted'] ?? false);

            $fit = (string) ($ly['fit'] ?? 'cover');
            if (! in_array($fit, ['fill', 'contain', 'cover'], true)) {
                $fit = 'cover';
            }

            $fadeInMs = max(0, (int) ($this->readAnimationMs($ly, 'fadeIn') ?? 0));
            $fadeOutMs = max(0, (int) ($this->readAnimationMs($ly, 'fadeOut') ?? 0));

            $extra = [];
            if ($type === 'text') {
                $style = is_array($ly['style'] ?? null) ? $ly['style'] : [];
                $extra = [
                    'content' => (string) ($ly['content'] ?? ''),
                    'font_family' => (string) ($style['fontFamily'] ?? 'sans-serif'),
                    'font_size' => max(8, (int) round((float) ($style['fontSize'] ?? 24))),
                    'color' => (string) ($style['color'] ?? '#ffffff'),
                    'line_height' => isset($style['lineHeight']) ? (float) $style['lineHeight'] : 1.25,
                    'text_align' => (string) ($style['textAlign'] ?? 'left'),
                ];
                $extra = StudioTextLayerFontExtras::mergeFromDocumentLayer($ly, $extra);
            }
            if ($type === 'image') {
                $extra['asset_id'] = (string) ($ly['assetId'] ?? '');
            }
            if ($type === 'generative_image') {
                $extra['asset_id'] = (string) ($ly['resultAssetId'] ?? '');
            }

            $outLayers[] = new RenderLayer(
                id: (string) ($ly['id'] ?? ''),
                type: $type === 'generative_image' ? 'image' : $type,
                zIndex: $z,
                startSeconds: $startMs / 1000.0,
                endSeconds: $endMs / 1000.0,
                visible: true,
                x: $x,
                y: $y,
                width: $lw,
                height: $lh,
                opacity: 1.0,
                rotationDegrees: $rot,
                fit: $fit,
                isPrimaryVideo: false,
                mediaPath: null,
                trimInMs: $trimInMs,
                trimOutMs: $trimOutMs,
                muted: $muted,
                fadeInMs: $fadeInMs,
                fadeOutMs: $fadeOutMs,
                extra: $extra,
            );
        }

        foreach ($layersRaw as $ly) {
            if (! is_array($ly)) {
                continue;
            }
            if (($ly['visible'] ?? true) === false) {
                continue;
            }
            if ((string) ($ly['type'] ?? '') !== 'video') {
                continue;
            }
            if ((string) ($ly['id'] ?? '') === $primaryId) {
                continue;
            }
            Log::warning('[CompositionRenderNormalizer] non-primary video layer omitted in ffmpeg_native v1', [
                'composition_id' => $composition->id,
                'layer_id' => $ly['id'] ?? null,
            ]);
        }

        usort($outLayers, static fn (RenderLayer $a, RenderLayer $b): int => $a->zIndex <=> $b->zIndex);

        return $outLayers;
    }

    public function buildTimeline(
        Composition $composition,
        array $primaryVideoLayer,
        float $probedSourceDurationSeconds,
    ): RenderTimeline {
        $doc = is_array($composition->document_json) ? $composition->document_json : [];
        $w = max(2, (int) ($doc['width'] ?? 0));
        $h = max(2, (int) ($doc['height'] ?? 0));
        $layersRaw = is_array($doc['layers'] ?? null) ? $doc['layers'] : [];
        $fps = max(1, (int) config('studio_video.canvas_export_default_fps', 30));
        $videoZ = (int) ($primaryVideoLayer['z'] ?? 0);
        $padColor = StudioCompositionVideoExportMediaHelper::resolvePadColorForFfmpeg($layersRaw, $videoZ);
        $timing = StudioCompositionVideoExportMediaHelper::computeTrimAndOutputDuration($doc, $primaryVideoLayer, $probedSourceDurationSeconds);
        $durationMs = max(1, (int) round($timing['output_duration_s'] * 1000));

        return new RenderTimeline($w, $h, $fps, $durationMs, $padColor);
    }

    /**
     * @param  array<string, mixed>  $layer
     */
    private function readAnimationMs(array $layer, string $kind): ?int
    {
        $anims = $layer['animations'] ?? $layer['studioAnimations'] ?? null;
        if (! is_array($anims)) {
            return null;
        }
        foreach ($anims as $a) {
            if (! is_array($a)) {
                continue;
            }
            if (($a['type'] ?? '') === $kind && isset($a['duration_ms']) && is_numeric($a['duration_ms'])) {
                return (int) $a['duration_ms'];
            }
        }

        return null;
    }
}
