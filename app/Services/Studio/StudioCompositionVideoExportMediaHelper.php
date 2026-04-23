<?php

namespace App\Services\Studio;

/**
 * Shared media rules for Studio composition video export (legacy bitmap + canvas_runtime merge).
 * Centralizes primary video selection, trim/duration caps, and pad color so canvas_runtime cannot drift.
 */
final class StudioCompositionVideoExportMediaHelper
{
    /**
     * Picks the base video for export: primaryForExport (visible) first, else lowest-z visible
     * video, else lowest-z of any video with an asset.
     *
     * @param  array<int, mixed>  $layers
     * @return array<string, mixed>|null
     */
    public static function selectPrimaryVideoLayer(array $layers): ?array
    {
        $candidates = [];
        foreach ($layers as $layer) {
            if (! is_array($layer) || ($layer['type'] ?? '') !== 'video' || empty($layer['assetId'])) {
                continue;
            }
            $candidates[] = $layer;
        }
        if ($candidates === []) {
            return null;
        }
        $visible = array_values(array_filter(
            $candidates,
            static fn (array $v): bool => ($v['visible'] ?? true) !== false
        ));
        $pool = $visible !== [] ? $visible : $candidates;
        foreach ($pool as $v) {
            if (! empty($v['primaryForExport'])) {
                return $v;
            }
        }
        usort($pool, static function (array $a, array $b): int {
            return ((int) ($a['z'] ?? 0)) <=> ((int) ($b['z'] ?? 0));
        });

        return $pool[0] ?? null;
    }

    /**
     * Same trim + duration cap rules as {@see StudioCompositionVideoExportService} (legacy export).
     *
     * @param  array<string, mixed>  $doc  composition document_json root
     * @param  array<string, mixed>  $videoLayer  primary video layer
     * @return array{
     *     trim_in_ms: int,
     *     trim_out_ms: int,
     *     trim_in_s: float,
     *     trim_out_s: float,
     *     available_s: float,
     *     composition_duration_ms: int,
     *     composition_duration_s: float,
     *     output_duration_s: float
     * }
     */
    public static function computeTrimAndOutputDuration(array $doc, array $videoLayer, float $probedSourceDurationSeconds): array
    {
        $tl = is_array($videoLayer['timeline'] ?? null) ? $videoLayer['timeline'] : [];
        $trimInMs = max(0, (int) ($tl['trim_in_ms'] ?? 0));
        $trimOutMs = max(0, (int) ($tl['trim_out_ms'] ?? 0));
        $trimInS = $trimInMs / 1000.0;
        $trimOutS = $trimOutMs / 1000.0;
        $availableS = max(0.04, $probedSourceDurationSeconds - $trimInS - $trimOutS);
        $compMs = 0;
        $stDoc = is_array($doc['studio_timeline'] ?? null) ? $doc['studio_timeline'] : null;
        if (is_array($stDoc) && isset($stDoc['duration_ms'])) {
            $compMs = max(0, (int) $stDoc['duration_ms']);
        }
        $compositionDurationS = $compMs > 0 ? $compMs / 1000.0 : $availableS;
        $outputDurationS = min($availableS, $compositionDurationS);

        return [
            'trim_in_ms' => $trimInMs,
            'trim_out_ms' => $trimOutMs,
            'trim_in_s' => $trimInS,
            'trim_out_s' => $trimOutS,
            'available_s' => $availableS,
            'composition_duration_ms' => $compMs,
            'composition_duration_s' => $compositionDurationS,
            'output_duration_s' => $outputDurationS,
        ];
    }

    /**
     * @param  array<int, mixed>  $layers
     */
    public static function resolvePadColorForFfmpeg(array $layers, int $videoZ): string
    {
        $resolved = self::resolveBackgroundFillCssColor($layers, $videoZ);
        if ($resolved === null) {
            return 'black';
        }
        $ffmpeg = self::cssColorToFfmpegPadColor($resolved);

        return $ffmpeg ?? 'black';
    }

    /**
     * @param  array<int, mixed>  $layers
     */
    private static function resolveBackgroundFillCssColor(array $layers, int $videoZ): ?string
    {
        $below = [];
        $any = [];
        foreach ($layers as $ly) {
            if (! is_array($ly) || ($ly['type'] ?? '') !== 'fill') {
                continue;
            }
            if (($ly['visible'] ?? true) === false) {
                continue;
            }
            $z = (int) ($ly['z'] ?? 0);
            $any[] = ['z' => $z, 'layer' => $ly];
            if ($z < $videoZ) {
                $below[] = ['z' => $z, 'layer' => $ly];
            }
        }
        $pool = $below !== [] ? $below : $any;
        if ($pool === []) {
            return null;
        }
        usort($pool, static fn (array $a, array $b): int => $a['z'] <=> $b['z']);
        $fill = $pool[0]['layer'];
        $fillKind = (string) ($fill['fillKind'] ?? 'solid');
        if ($fillKind === 'gradient') {
            $g = (string) ($fill['gradientEndColor'] ?? $fill['gradientStartColor'] ?? $fill['color'] ?? '');

            return $g !== '' ? $g : null;
        }

        $c = (string) ($fill['color'] ?? '');
        if ($c === '') {
            return null;
        }

        return $c;
    }

    /**
     * @return non-empty-string|null  FFmpeg pad color (named color or {@code 0xRRGGBB})
     */
    private static function cssColorToFfmpegPadColor(string $css): ?string
    {
        $s = trim($css);
        if ($s === '') {
            return null;
        }
        if (preg_match('/^#([0-9a-f]{3})$/i', $s, $m)) {
            $x = $m[1];

            return '0x'.strtoupper($x[0].$x[0].$x[1].$x[1].$x[2].$x[2]);
        }
        if (preg_match('/^#([0-9a-f]{6})([0-9a-f]{2})?$/i', $s, $m)) {
            return '0x'.strtoupper($m[1]);
        }
        if (preg_match('/^rgba?\(\s*([0-9]+)\s*,\s*([0-9]+)\s*,\s*([0-9]+)/i', $s, $m)) {
            $r = max(0, min(255, (int) $m[1]));
            $g = max(0, min(255, (int) $m[2]));
            $b = max(0, min(255, (int) $m[3]));

            return sprintf('0x%02X%02X%02X', $r, $g, $b);
        }

        return null;
    }

    public static function ffprobeDurationSeconds(string $ffprobe, string $path): float
    {
        if (! is_file($path)) {
            return 0.0;
        }
        $p = new \Symfony\Component\Process\Process([$ffprobe, '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1', $path]);
        $p->setTimeout(60);
        $p->run();
        if (! $p->isSuccessful()) {
            return 0.0;
        }
        $raw = trim($p->getOutput() ?: '');
        if ($raw === '' || $raw === 'N/A') {
            return 0.0;
        }
        $f = (float) $raw;

        return $f > 0 ? $f : 0.0;
    }

    public static function ffprobeHasAudio(string $ffprobe, string $path): bool
    {
        if (! is_file($path)) {
            return false;
        }
        $p = new \Symfony\Component\Process\Process([$ffprobe, '-v', 'error', '-select_streams', 'a:0', '-show_entries', 'stream=index', '-of', 'csv=p=0', $path]);
        $p->setTimeout(60);
        $p->run();

        return $p->isSuccessful() && trim($p->getOutput() ?: '') !== '';
    }
}
