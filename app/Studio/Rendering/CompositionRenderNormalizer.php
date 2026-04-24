<?php

namespace App\Studio\Rendering;

use App\Models\Composition;
use App\Services\Studio\StudioCompositionVideoExportMediaHelper;
use App\Studio\Rendering\Dto\CompositionRenderPlan;
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
        return $this->buildOverlayPlan($composition, $primaryVideoLayer, $timeline)->overlayLayers;
    }

    /**
     * @param  array<string, mixed>  $primaryVideoLayer
     */
    public function buildOverlayPlan(
        Composition $composition,
        array $primaryVideoLayer,
        RenderTimeline $timeline,
    ): CompositionRenderPlan {
        $doc = is_array($composition->document_json) ? $composition->document_json : [];
        $layersRaw = is_array($doc['layers'] ?? null) ? $doc['layers'] : [];
        $videoZ = (int) ($primaryVideoLayer['z'] ?? 0);
        $primaryId = (string) ($primaryVideoLayer['id'] ?? '');
        $durationMs = max(1, $timeline->durationMs);

        $events = [];
        $unsupportedVisible = [];
        $skippedBelowPrimary = [];

        $outLayers = [];
        foreach ($layersRaw as $ly) {
            if (! is_array($ly)) {
                continue;
            }
            $id = (string) ($ly['id'] ?? '');
            $rawType = (string) ($ly['type'] ?? '');
            $visible = ($ly['visible'] ?? true) !== false;

            if (! $visible) {
                $this->pushNormEvent($events, $rawType, null, $id, false, true, 'not_visible');

                continue;
            }

            if ($rawType === 'video') {
                if ($id === $primaryId) {
                    $this->pushNormEvent($events, $rawType, 'video_primary', $id, true, true, 'primary_base');

                    continue;
                }
                Log::warning('[CompositionRenderNormalizer] non-primary video layer omitted in ffmpeg_native v1', [
                    'composition_id' => $composition->id,
                    'layer_id' => $id,
                ]);
                $this->pushNormEvent($events, $rawType, null, $id, true, false, 'non_primary_video_v1');

                continue;
            }

            if ($rawType === 'mask') {
                $this->pushNormEvent($events, $rawType, null, $id, true, false, 'mask_handled_by_feature_policy');

                continue;
            }

            $canonical = $this->canonicalOverlayType($ly);
            $this->pushNormEvent($events, $rawType, $canonical, $id, true, $canonical !== null, $canonical === null ? 'unmapped_type' : null);

            if ($canonical === null) {
                $unsupportedVisible[] = [
                    'layer_id' => $id,
                    'type' => $rawType,
                    'reason' => 'unknown_or_unsupported_layer_type',
                ];

                continue;
            }

            $z = (int) ($ly['z'] ?? 0);
            if ($z < $videoZ) {
                $skippedBelowPrimary[] = [
                    'layer_id' => $id,
                    'type' => $rawType,
                    'canonical' => $canonical,
                    'z' => $z,
                    'primary_video_z' => $videoZ,
                    'reason' => 'below_primary_video_z_v1',
                ];
                $this->pushNormEvent($events, $rawType, $canonical, $id, true, false, 'below_primary_video_z');

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

            $blendModeNorm = strtolower(trim((string) ($ly['blendMode'] ?? $ly['blend_mode'] ?? 'normal')));
            if ($blendModeNorm === '') {
                $blendModeNorm = 'normal';
            }

            if ($canonical === 'text') {
                $textBody = $this->extractTextContent($ly);
                if (trim($textBody) === '') {
                    $unsupportedVisible[] = [
                        'layer_id' => $id,
                        'type' => $rawType,
                        'reason' => 'text_layer_empty_content',
                    ];
                    $this->pushNormEvent($events, $rawType, $canonical, $id, true, false, 'empty_text');

                    continue;
                }
                $style = $this->mergeStyleSources($ly);
                $fontSize = max(8, (int) round((float) $this->firstNumericKey($style, ['fontSize', 'font_size', 'size'], 24)));
                $color = (string) $this->firstStringKey($style, ['color', 'fill', 'fillColor', 'textColor'], '#ffffff');
                $fontFamily = (string) $this->firstStringKey($style, ['fontFamily', 'font_family'], 'sans-serif');
                $fontWeight = (int) round((float) $this->firstNumericKey($style, ['fontWeight', 'font_weight'], 400));
                $lineHeight = isset($style['lineHeight']) ? (float) $style['lineHeight'] : (isset($style['line_height']) ? (float) $style['line_height'] : 1.25);
                $textAlign = (string) $this->firstStringKey($style, ['textAlign', 'text_align'], 'left');
                if (! in_array($textAlign, ['left', 'center', 'right'], true)) {
                    $textAlign = 'left';
                }
                $extra = [
                    'content' => $textBody,
                    'font_family' => $fontFamily,
                    'font_weight' => $fontWeight,
                    'font_size' => $fontSize,
                    'color' => $color,
                    'line_height' => $lineHeight,
                    'text_align' => $textAlign,
                ];
                $extra = StudioTextLayerFontExtras::mergeFromDocumentLayer($ly, $extra);
                $extra['blend_mode'] = $blendModeNorm;
                $outLayers[] = new RenderLayer(
                    id: $id,
                    type: 'text',
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

                continue;
            }

            if ($canonical === 'image') {
                $docType = $rawType === 'generative_image' ? 'generative_image' : 'image';
                $extra = [
                    'asset_id' => $this->resolveOverlayImageAssetId($ly, $docType),
                    'blend_mode' => $blendModeNorm,
                ];
                $outLayers[] = new RenderLayer(
                    id: $id,
                    type: 'image',
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

                continue;
            }

            if ($canonical === 'fill') {
                $spec = $this->buildFillRasterSpec($ly, $id);
                if ($spec === null) {
                    $unsupportedVisible[] = [
                        'layer_id' => $id,
                        'type' => $rawType,
                        'reason' => 'fill_radial_or_unsupported_v1',
                    ];
                    $this->pushNormEvent($events, $rawType, $canonical, $id, true, false, 'fill_unsupported');

                    continue;
                }
                $outLayers[] = new RenderLayer(
                    id: $id,
                    type: 'image',
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
                    fit: 'fill',
                    isPrimaryVideo: false,
                    mediaPath: null,
                    trimInMs: $trimInMs,
                    trimOutMs: $trimOutMs,
                    muted: $muted,
                    fadeInMs: $fadeInMs,
                    fadeOutMs: $fadeOutMs,
                    extra: [
                        'studio_preraster' => 'fill_shape',
                        'fill_shape_spec' => $spec,
                        'asset_id' => '',
                        'blend_mode' => $blendModeNorm,
                    ],
                );

                continue;
            }

            if ($canonical === 'shape') {
                $shapeStyle = $this->mergeLayerRootStyleWithNested($ly);
                $shapeKind = strtolower(trim((string) ($ly['shapeKind'] ?? $ly['shape'] ?? 'rect')));
                if (in_array($shapeKind, ['ellipse', 'circle', 'oval'], true)) {
                    $fill = (string) $this->firstStringKey($shapeStyle, ['fill', 'fillColor', 'color'], '#ffffff');
                    $spec = ['kind' => 'shape_ellipse', 'fill' => $fill];
                } else {
                    $fill = (string) $this->firstStringKey($shapeStyle, ['fill', 'fillColor', 'color'], '#ffffff');
                    $radius = (float) ($ly['borderRadius'] ?? $ly['border_radius'] ?? 0);
                    $spec = ['kind' => 'shape_rect', 'fill' => $fill, 'border_radius' => $radius];
                }
                $outLayers[] = new RenderLayer(
                    id: $id,
                    type: 'image',
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
                    fit: 'fill',
                    isPrimaryVideo: false,
                    mediaPath: null,
                    trimInMs: $trimInMs,
                    trimOutMs: $trimOutMs,
                    muted: $muted,
                    fadeInMs: $fadeInMs,
                    fadeOutMs: $fadeOutMs,
                    extra: [
                        'studio_preraster' => 'fill_shape',
                        'fill_shape_spec' => $spec,
                        'asset_id' => '',
                        'blend_mode' => $blendModeNorm,
                    ],
                );
            }
        }

        usort($outLayers, static fn (RenderLayer $a, RenderLayer $b): int => $a->zIndex <=> $b->zIndex);

        $diagnostics = [
            'total_document_layers' => count($layersRaw),
            'normalized_overlay_count' => count($outLayers),
            'normalization_events' => $events,
            'unsupported_visible' => $unsupportedVisible,
            'skipped_below_primary_video' => $skippedBelowPrimary,
        ];

        return new CompositionRenderPlan($outLayers, $diagnostics);
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function pushNormEvent(
        array &$events,
        string $rawType,
        ?string $normalizedType,
        string $layerId,
        bool $visible,
        bool $supported,
        ?string $reason,
    ): void {
        $row = [
            'original_type' => $rawType,
            'normalized_type' => $normalizedType,
            'layer_id' => $layerId,
            'visible' => $visible,
            'supported' => $supported,
            'reason' => $reason,
        ];
        $events[] = $row;
        Log::info('Studio native layer normalization', $row);
    }

    /**
     * @param  array<string, mixed>  $ly
     */
    private function canonicalOverlayType(array $ly): ?string
    {
        $raw = strtolower(trim((string) ($ly['type'] ?? '')));
        $textAliases = ['text', 'typography', 'live_text', 'livetext', 'live_type', 'livetype', 'textbox', 'text_box'];
        if (in_array($raw, $textAliases, true)) {
            return 'text';
        }
        if ($raw === 'type' && $this->rawTypeHintsText($ly)) {
            return 'text';
        }
        if ($raw === 'image') {
            return 'image';
        }
        if ($raw === 'generative_image') {
            return 'image';
        }
        if ($raw === 'fill') {
            return 'fill';
        }
        $shapeAliases = ['rect', 'rectangle', 'rounded_rect', 'shape', 'vector', 'ellipse', 'circle'];
        if (in_array($raw, $shapeAliases, true)) {
            return 'shape';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $ly
     */
    private function rawTypeHintsText(array $ly): bool
    {
        if (isset($ly['content']) || isset($ly['text']) || isset($ly['value']) || isset($ly['label'])) {
            return true;
        }
        if (isset($ly['style']) && is_array($ly['style'])) {
            return true;
        }
        $props = $ly['props'] ?? null;
        if (is_array($props) && (isset($props['text']) || isset($props['content']) || isset($props['label']))) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $ly
     */
    private function extractTextContent(array $ly): string
    {
        $chunks = [];
        foreach (['content', 'text', 'value', 'label'] as $k) {
            if (isset($ly[$k]) && is_string($ly[$k])) {
                $chunks[] = $ly[$k];
            }
        }
        $props = $ly['props'] ?? null;
        if (is_array($props)) {
            foreach (['text', 'content', 'label', 'value'] as $k) {
                if (isset($props[$k]) && is_string($props[$k])) {
                    $chunks[] = $props[$k];
                }
            }
        }
        $style = $ly['style'] ?? null;
        if (is_array($style) && isset($style['text']) && is_string($style['text'])) {
            $chunks[] = $style['text'];
        }
        $body = trim(implode("\n", array_filter($chunks)));
        if ($body !== '' && str_contains($body, '<')) {
            $body = trim(html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return $body;
    }

    /**
     * Merges top-level paint/typography keys (some editor payloads set {@code color} on the layer root)
     * with nested {@code style}/{@code defaults}/{@code props}.
     *
     * @param  array<string, mixed>  $ly
     * @return array<string, mixed>
     */
    private function mergeLayerRootStyleWithNested(array $ly): array
    {
        $root = [];
        foreach (['color', 'fill', 'fillColor', 'fontFamily', 'fontSize', 'font_family', 'font_size'] as $k) {
            if (array_key_exists($k, $ly) && (is_string($ly[$k]) || is_numeric($ly[$k]))) {
                $root[$k] = $ly[$k];
            }
        }

        return array_merge($root, $this->mergeStyleSources($ly));
    }

    /**
     * @param  array<string, mixed>  $ly
     * @return array<string, mixed>
     */
    private function mergeStyleSources(array $ly): array
    {
        return StudioTextLayerFontExtras::mergeShallowStyleSources($ly);
    }

    /**
     * @param  array<string, mixed>  $style
     * @param  list<string>  $keys
     */
    private function firstNumericKey(array $style, array $keys, float $default): float
    {
        foreach ($keys as $k) {
            if (isset($style[$k]) && is_numeric($style[$k])) {
                return (float) $style[$k];
            }
        }

        return $default;
    }

    /**
     * @param  array<string, mixed>  $style
     * @param  list<string>  $keys
     */
    private function firstStringKey(array $style, array $keys, string $default): string
    {
        foreach ($keys as $k) {
            if (isset($style[$k]) && is_string($style[$k]) && trim($style[$k]) !== '') {
                return trim($style[$k]);
            }
        }

        return $default;
    }

    /**
     * @param  array<string, mixed>  $ly
     * @return ?array<string, mixed>
     */
    private function buildFillRasterSpec(array $ly, string $layerId): ?array
    {
        $radius = (float) ($ly['borderRadius'] ?? $ly['border_radius'] ?? 0);

        if (strtolower((string) ($ly['textBoostStyle'] ?? '')) === 'radial') {
            $opacity = max(0.0, min(1.0, (float) ($ly['textBoostOpacity'] ?? 0.7)));
            $primaryHex = (string) ($ly['textBoostColor'] ?? $ly['color'] ?? '#000000');
            $secondary = trim((string) ($ly['textBoostSecondaryColor'] ?? ''));
            $scaleRaw = $ly['textBoostGradientScale'] ?? null;
            $scale = 1.0;
            if ($scaleRaw !== null && $scaleRaw !== '' && is_numeric($scaleRaw)) {
                $scale = (float) $scaleRaw;
            }
            $scale = min(2.5, max(0.35, $scale));

            return [
                'kind' => 'fill_radial_text_boost',
                'color_center_hex' => $secondary !== '' ? $secondary : 'transparent',
                'color_edge_hex' => $primaryHex,
                'opacity' => $opacity,
                'gradient_scale' => $scale,
                'border_radius' => $radius,
                'layer_id' => $layerId,
            ];
        }

        $fillKind = (string) ($ly['fillKind'] ?? $ly['fill_kind'] ?? 'solid');
        if ($fillKind === 'gradient') {
            $start = (string) ($ly['gradientStartColor'] ?? 'transparent');
            $end = (string) ($ly['gradientEndColor'] ?? $ly['color'] ?? '#000000');
            $angle = (float) ($ly['gradientAngleDeg'] ?? $ly['gradient_angle_deg'] ?? 180);

            return [
                'kind' => 'fill_gradient_linear',
                'color_start' => $start,
                'color_end' => $end,
                'gradient_angle_deg' => $angle,
                'border_radius' => $radius,
                'layer_id' => $layerId,
            ];
        }
        $color = (string) ($ly['color'] ?? '#000000');

        return [
            'kind' => 'fill_solid',
            'fill' => $color,
            'border_radius' => $radius,
            'layer_id' => $layerId,
        ];
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
     * @param  array<string, mixed>  $ly
     */
    private function resolveOverlayImageAssetId(array $ly, string $documentType): string
    {
        $firstNonEmpty = static function (array $keys) use ($ly): string {
            foreach ($keys as $k) {
                if (! array_key_exists($k, $ly)) {
                    continue;
                }
                $s = trim((string) $ly[$k]);

                if ($s !== '') {
                    return $s;
                }
            }

            return '';
        };

        if ($documentType === 'generative_image') {
            $id = $firstNonEmpty(['resultAssetId', 'result_asset_id', 'assetId', 'asset_id']);
            if ($id !== '') {
                return $id;
            }
        } else {
            $id = $firstNonEmpty(['assetId', 'asset_id', 'resultAssetId', 'result_asset_id']);
            if ($id !== '') {
                return $id;
            }
        }

        $nested = is_array($ly['asset'] ?? null) ? $ly['asset'] : [];
        $id = trim((string) ($nested['id'] ?? $nested['Id'] ?? ''));
        if ($id !== '') {
            return $id;
        }

        return $this->extractAssetIdFromEditorAssetFileSrc((string) ($ly['src'] ?? ''));
    }

    /**
     * Same-origin editor bridge URLs carry the asset UUID in the path.
     */
    private function extractAssetIdFromEditorAssetFileSrc(string $src): string
    {
        $src = trim($src);
        if ($src === '') {
            return '';
        }
        $candidates = [$src, rawurldecode($src)];
        foreach ($candidates as $candidate) {
            if (preg_match('#/app/api/assets/([0-9a-fA-F-]{36})/(file|thumbnail)(?:[?#]|$)#i', $candidate, $m)) {
                return $m[1];
            }
            if (preg_match('#/app/api/assets/(\d+)/(file|thumbnail)(?:[?#]|$)#i', $candidate, $m)) {
                return $m[1];
            }
            if (preg_match('#https?://[^/]+/app/api/assets/([0-9a-fA-F-]{36})/(file|thumbnail)(?:[?#]|$)#i', $candidate, $m)) {
                return $m[1];
            }
            if (preg_match('#https?://[^/]+/app/api/assets/(\d+)/(file|thumbnail)(?:[?#]|$)#i', $candidate, $m)) {
                return $m[1];
            }
        }

        return '';
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
