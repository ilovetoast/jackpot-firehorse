<?php

namespace App\Studio\Rendering;

use App\Studio\Rendering\Dto\RenderLayer;
use App\Studio\Rendering\Dto\RenderTimeline;

/**
 * Builds {@code -filter_complex} graphs for Studio FFmpeg-native composition export.
 */
final class FfmpegFilterGraphBuilder
{
    /**
     * Document {@code blendMode} (CSS mix-blend-mode) → FFmpeg {@code blend} filter {@code all_mode} value.
     * Keys are lowercased editor strings; values match libavfilter blend modes. Unknown / normal → omitted (use overlay).
     *
     * @var array<string, string>
     */
    private const DOCUMENT_BLEND_TO_FFMPEG_ALL_MODE = [
        'multiply' => 'multiply',
        'screen' => 'screen',
        'overlay' => 'overlay',
        'darken' => 'darken',
        'lighten' => 'lighten',
        'color-dodge' => 'dodge',
        'color-burn' => 'burn',
        'hard-light' => 'hardlight',
        'soft-light' => 'softlight',
        'difference' => 'difference',
        'exclusion' => 'exclusion',
        // CSS hue / saturation / color / luminosity are not libavfilter `blend` modes — omit so we use classic `overlay`.
    ];

    /**
     * @param  list<RenderLayer>  $overlays
     * @return array{filter_complex: string, video_out_label: string, input_count: int}
     */
    public function buildOverlayGraph(RenderTimeline $timeline, array $overlays): array
    {
        $w = $timeline->width;
        $h = $timeline->height;
        $pad = $this->escapePadColor($timeline->padColorFfmpeg);
        $parts = [];
        $parts[] = sprintf(
            '[0:v]scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2:%s,setsar=1[bg0]',
            $w,
            $h,
            $w,
            $h,
            $pad
        );
        $usable = [];
        foreach ($overlays as $ly) {
            if ($ly->mediaPath !== null && $ly->mediaPath !== '') {
                $usable[] = $ly;
            }
        }

        $cur = 'bg0';
        $idx = 1;
        foreach ($usable as $oi => $ly) {
            $tw = max(1, $ly->width);
            $th = max(1, $ly->height);
            $fit = $ly->fit;
            if ($fit === 'fill') {
                $fitExpr = sprintf('scale=%d:%d,format=rgba', $tw, $th);
            } elseif ($fit === 'contain') {
                $fitExpr = sprintf(
                    'scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2:color=black@0,format=rgba',
                    $tw,
                    $th,
                    $tw,
                    $th
                );
            } else {
                $fitExpr = sprintf(
                    'scale=%d:%d:force_original_aspect_ratio=increase,crop=%d:%d,format=rgba',
                    $tw,
                    $th,
                    $tw,
                    $th
                );
            }
            $raw = 'ovraw'.$oi;
            $next = 'bg'.($oi + 1);
            $parts[] = sprintf('[%d:v]%s[%s]', $idx, $fitExpr, $raw);

            $fadeChain = $this->fadeChain($ly);
            $processed = $raw;
            if ($fadeChain !== '') {
                $processed = 'ovp'.$oi;
                $parts[] = sprintf('[%s]%s[%s]', $raw, $fadeChain, $processed);
            }
            $alphaLabel = $processed;
            $x = $ly->x;
            $y = $ly->y;
            $st = sprintf('%.6f', max(0.0, $ly->startSeconds));
            $en = sprintf('%.6f', max($ly->startSeconds + 0.001, $ly->endSeconds));
            $enable = sprintf("between(t\\,%s\\,%s)", $st, $en);
            $blendMode = strtolower(trim((string) ($ly->extra['blend_mode'] ?? 'normal')));
            $ffmpegBlend = self::DOCUMENT_BLEND_TO_FFMPEG_ALL_MODE[$blendMode] ?? null;
            if ($ffmpegBlend !== null) {
                $trans = 'tr'.$oi;
                $ovfull = 'ovfull'.$oi;
                $dStr = sprintf('%.6f', $timeline->outputDurationSeconds());
                $fpsInt = max(1, $timeline->fps);
                $parts[] = sprintf(
                    'color=c=black@0.0:s=%dx%d:r=%d:d=%s,format=rgba,setpts=PTS-STARTPTS[%s]',
                    $w,
                    $h,
                    $fpsInt,
                    $dStr,
                    $trans
                );
                $parts[] = sprintf(
                    '[%s][%s]overlay=%d:%d:enable=\'%s\':shortest=1:format=auto[%s]',
                    $trans,
                    $alphaLabel,
                    $x,
                    $y,
                    $enable,
                    $ovfull
                );
                $parts[] = sprintf(
                    '[%s][%s]blend=all_mode=%s:shortest=1[%s]',
                    $cur,
                    $ovfull,
                    $ffmpegBlend,
                    $next
                );
            } else {
                $parts[] = sprintf(
                    '[%s][%s]overlay=%d:%d:enable=\'%s\':shortest=1:format=auto[%s]',
                    $cur,
                    $alphaLabel,
                    $x,
                    $y,
                    $enable,
                    $next
                );
            }
            $cur = $next;
            $idx++;
        }
        $parts[] = sprintf('[%s]format=yuv420p[vout]', $cur);

        return [
            'filter_complex' => implode(';', $parts),
            'video_out_label' => 'vout',
            'input_count' => 1 + count($usable),
        ];
    }

    private function fadeChain(RenderLayer $ly): string
    {
        $parts = [];
        if ($ly->fadeInMs > 0) {
            $d = max(0.001, $ly->fadeInMs / 1000.0);
            $st = sprintf('%.6f', $ly->startSeconds);
            $parts[] = sprintf('fade=t=in:st=%s:d=%s:alpha=1', $st, sprintf('%.6f', $d));
        }
        if ($ly->fadeOutMs > 0) {
            $d = max(0.001, $ly->fadeOutMs / 1000.0);
            $st = max(0.0, $ly->endSeconds - $d);
            $parts[] = sprintf('fade=t=out:st=%s:d=%s:alpha=1', sprintf('%.6f', $st), sprintf('%.6f', $d));
        }

        return implode(',', $parts);
    }

    private function escapePadColor(string $padColor): string
    {
        return str_replace(['\\', ':'], ['\\\\', '\\:'], $padColor);
    }
}
