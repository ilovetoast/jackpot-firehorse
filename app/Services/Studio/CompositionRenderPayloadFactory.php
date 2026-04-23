<?php

namespace App\Services\Studio;

use App\Http\Controllers\Editor\EditorBrandContextController;
use App\Models\Brand;
use App\Models\Composition;
use App\Models\StudioCompositionVideoExportJob;
use App\Models\Tenant;
use App\Models\User;

/**
 * Builds the **canonical render payload** (versioned) consumed by the internal export render page and headless workers.
 *
 * This is additive: the editor continues to persist {@see Composition::$document_json}; we derive a stable contract here.
 */
final class CompositionRenderPayloadFactory
{
    public const VERSION = 1;

    /**
     * @return array{
     *     version: int,
     *     width: int,
     *     height: int,
     *     fps: int,
     *     duration_ms: int,
     *     background: array<string, mixed>,
     *     layers: list<array<string, mixed>>,
     *     fonts: list<array<string, mixed>>,
     *     timing: array<string, mixed>,
     *     export_job_id: int,
     *     composition_id: int,
     *     tenant_id: int,
     *     brand_id: int,
     *     user_id: int|null,
     *     brand_context: array<string, mixed>|null
     * }
     */
    public static function fromComposition(
        Composition $composition,
        Tenant $tenant,
        ?User $user,
        StudioCompositionVideoExportJob $job,
    ): array {
        $composition->loadMissing(['brand']);
        $doc = is_array($composition->document_json) ? $composition->document_json : [];
        $w = max(1, (int) ($doc['width'] ?? 1080));
        $h = max(1, (int) ($doc['height'] ?? 1080));
        $layersRaw = is_array($doc['layers'] ?? null) ? $doc['layers'] : [];
        $layersSorted = array_values(array_filter($layersRaw, static fn ($ly): bool => is_array($ly)));
        usort($layersSorted, static function ($a, $b): int {
            if (! is_array($a) || ! is_array($b)) {
                return 0;
            }

            return ((int) ($a['z'] ?? 0)) <=> ((int) ($b['z'] ?? 0));
        });
        $studioTimeline = is_array($doc['studio_timeline'] ?? null) ? $doc['studio_timeline'] : [];
        $durationMs = max(1, (int) ($studioTimeline['duration_ms'] ?? ($job->duration_ms ?? 30_000)));
        $fps = max(1, (int) config('studio_video.canvas_export_default_fps', 30));

        $brandContext = null;
        if ($composition->brand instanceof Brand) {
            $brandContext = app(EditorBrandContextController::class)
                ->serializeBrandContextForBrand($composition->brand);
        }

        return [
            'version' => self::VERSION,
            'width' => $w,
            'height' => $h,
            'fps' => $fps,
            'duration_ms' => $durationMs,
            'background' => self::inferBackground($layersSorted),
            'layers' => array_values(array_map(static fn ($ly) => is_array($ly) ? $ly : [], $layersSorted)),
            'fonts' => self::buildFontsListFromLayers($layersSorted, $brandContext),
            'timing' => [
                'composition_duration_ms' => $durationMs,
                'schema' => 'studio_timeline_v1',
            ],
            'export_job_id' => (int) $job->id,
            'composition_id' => (int) $composition->id,
            'tenant_id' => (int) $tenant->id,
            'brand_id' => (int) $composition->brand_id,
            'user_id' => $user?->id ?? $job->user_id,
            'brand_context' => $brandContext,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $layersSorted
     * @param  array<string, mixed>|null  $brandContext
     * @return list<array<string, mixed>>
     */
    private static function buildFontsListFromLayers(array $layersSorted, ?array $brandContext): array
    {
        $out = [];
        $seen = [];

        if (is_array($brandContext)) {
            $typography = is_array($brandContext['typography'] ?? null) ? $brandContext['typography'] : [];
            foreach ($typography['stylesheet_urls'] ?? [] as $u) {
                if (! is_string($u) || $u === '') {
                    continue;
                }
                $key = 'stylesheet:'.$u;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = [
                    'kind' => 'stylesheet',
                    'url' => $u,
                ];
            }
            foreach ($typography['font_face_sources'] ?? [] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $assetId = $row['asset_id'] ?? null;
                $key = 'font_face:'.(string) $assetId.':'.(string) ($row['weight'] ?? '').':'.(string) ($row['style'] ?? '');
                if ($assetId === null || $assetId === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = array_merge(['kind' => 'font_face_source'], $row);
            }
        }

        foreach ($layersSorted as $ly) {
            if (! is_array($ly) || ($ly['type'] ?? '') !== 'text') {
                continue;
            }
            if (($ly['visible'] ?? true) === false) {
                continue;
            }
            $style = is_array($ly['style'] ?? null) ? $ly['style'] : [];
            $fam = trim((string) ($style['fontFamily'] ?? ''));
            if ($fam === '') {
                continue;
            }
            $key = 'text_family:'.$fam;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'kind' => 'text_layer_family',
                'family' => $fam,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $layersSorted
     * @return array{type: string, color?: string}
     */
    private static function inferBackground(array $layersSorted): array
    {
        foreach ($layersSorted as $ly) {
            if (! is_array($ly) || ($ly['type'] ?? '') !== 'fill') {
                continue;
            }
            if (($ly['visible'] ?? true) === false) {
                continue;
            }

            return [
                'type' => 'fill',
                'color' => (string) ($ly['color'] ?? '#000000'),
                'fillKind' => (string) ($ly['fillKind'] ?? 'solid'),
            ];
        }

        return ['type' => 'solid', 'color' => '#000000'];
    }
}
