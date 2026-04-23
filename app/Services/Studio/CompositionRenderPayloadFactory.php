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

        $payload = [
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

        return self::rewriteWorkerReachableOriginsInPayload($payload);
    }

    /**
     * When {@see config('studio_video.canvas_export_signed_url_root')} is set, Playwright loads the Inertia export page
     * from that origin while {@see config('app.url')} may still point at a browser-only host. Layer `src` values and
     * brand typography URLs often embed {@code APP_URL} (or the alternate scheme, {@code ASSET_URL}, or
     * {@code STUDIO_VIDEO_CANVAS_EXPORT_PAYLOAD_EXTRA_ORIGINS}); without rewriting, Chromium logs ERR_CONNECTION_REFUSED and
     * the export bridge never becomes ready (waitForFunction timeout).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function rewriteWorkerReachableOriginsInPayload(array $payload): array
    {
        $target = trim((string) config('studio_video.canvas_export_signed_url_root', ''));
        if ($target === '') {
            return $payload;
        }
        $to = rtrim($target, '/');
        if ($to === '') {
            return $payload;
        }
        $sourceOrigins = self::collectPayloadRewriteSourceOrigins();
        $sourceOrigins = array_values(array_filter(
            $sourceOrigins,
            static fn (string $o): bool => rtrim($o, '/') !== $to,
        ));
        if ($sourceOrigins === []) {
            return $payload;
        }
        $prefixes = self::buildRewritePrefixesLongestFirst($sourceOrigins);

        return self::rewriteOriginPrefixDeep($payload, $to, $prefixes);
    }

    /**
     * Origins that should be rewritten to {@see config('studio_video.canvas_export_signed_url_root')} when set.
     *
     * @return list<string>
     */
    private static function collectPayloadRewriteSourceOrigins(): array
    {
        $out = [];
        $add = static function (string $s) use (&$out): void {
            $s = rtrim(trim($s), '/');
            if ($s === '') {
                return;
            }
            $out[] = $s;
        };
        $add((string) config('app.url'));
        $app = rtrim(trim((string) config('app.url')), '/');
        if ($app !== '' && preg_match('#^https://#i', $app) === 1) {
            $add('http://'.substr($app, 8));
        } elseif ($app !== '' && preg_match('#^http://#i', $app) === 1) {
            $add('https://'.substr($app, 7));
        }
        $asset = trim((string) config('app.asset_url'));
        if ($asset !== '') {
            $add($asset);
            $ar = rtrim($asset, '/');
            if (preg_match('#^https://#i', $ar) === 1) {
                $add('http://'.substr($ar, 8));
            } elseif (preg_match('#^http://#i', $ar) === 1) {
                $add('https://'.substr($ar, 7));
            }
        }
        $raw = trim((string) config('studio_video.canvas_export_payload_extra_origins', ''));
        foreach (preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $piece) {
            $p = rtrim(trim((string) $piece), '/');
            if ($p === '') {
                continue;
            }
            $add($p);
            if (preg_match('#^https://#i', $p) === 1) {
                $add('http://'.substr($p, 8));
            } elseif (preg_match('#^http://#i', $p) === 1) {
                $add('https://'.substr($p, 7));
            }
        }

        $uniq = [];
        $deduped = [];
        foreach ($out as $o) {
            $k = strtolower($o);
            if (isset($uniq[$k])) {
                continue;
            }
            $uniq[$k] = true;
            $deduped[] = $o;
        }

        return $deduped;
    }

    /**
     * @param  list<string>  $origins
     * @return list<string>
     */
    private static function buildRewritePrefixesLongestFirst(array $origins): array
    {
        $seen = [];
        $prefixes = [];
        foreach ($origins as $origin) {
            $o = rtrim(trim($origin), '/');
            if ($o === '') {
                continue;
            }
            if (! isset($seen[strtolower($o)])) {
                $seen[strtolower($o)] = true;
                $prefixes[] = $o;
            }
            $rel = self::httpOriginToProtocolRelativeAuthority($o);
            if ($rel !== null && ! isset($seen[strtolower($rel)])) {
                $seen[strtolower($rel)] = true;
                $prefixes[] = $rel;
            }
        }
        usort($prefixes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $prefixes;
    }

    private static function httpOriginToProtocolRelativeAuthority(string $origin): ?string
    {
        if (preg_match('#^https?://#i', $origin) !== 1) {
            return null;
        }
        /** @var array{host?: string, port?: int} $parts */
        $parts = parse_url($origin);
        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }
        $auth = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        return '//'.$auth;
    }

    private static function rewriteUrlString(string $value, string $toOrigin, array $prefixesLongestFirst): string
    {
        $to = rtrim($toOrigin, '/');
        foreach ($prefixesLongestFirst as $prefix) {
            if ($prefix === '' || $prefix === $to) {
                continue;
            }
            if (str_starts_with($value, $prefix.'/')) {
                return $to.substr($value, strlen($prefix));
            }
            if ($value === $prefix) {
                return $to;
            }
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $data
     * @param  list<string>  $prefixesLongestFirst
     * @return array<string, mixed>|list<mixed>
     */
    private static function rewriteOriginPrefixDeep(array $data, string $toOrigin, array $prefixesLongestFirst): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $out[$key] = self::rewriteOriginPrefixValue($value, $toOrigin, $prefixesLongestFirst);
        }

        return $out;
    }

    /**
     * @param  list<string>  $prefixesLongestFirst
     */
    private static function rewriteOriginPrefixValue(mixed $value, string $toOrigin, array $prefixesLongestFirst): mixed
    {
        if (is_string($value)) {
            return self::rewriteUrlString($value, $toOrigin, $prefixesLongestFirst);
        }
        if (is_array($value)) {
            return self::rewriteOriginPrefixDeep($value, $toOrigin, $prefixesLongestFirst);
        }

        return $value;
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
