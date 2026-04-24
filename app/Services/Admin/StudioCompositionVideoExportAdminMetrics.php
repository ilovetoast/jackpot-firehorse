<?php

namespace App\Services\Admin;

use App\Models\StudioCompositionVideoExportJob;
use App\Models\Tenant;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only aggregates and recent rows for admin visibility into Studio MP4 export failures (all render modes).
 */
final class StudioCompositionVideoExportAdminMetrics
{
    public static function tableExists(): bool
    {
        return Schema::hasTable('studio_composition_video_export_jobs');
    }

    /**
     * @return array{
     *     last_24h: int,
     *     last_7d: int,
     *     by_code: list<array{code: string, count: int}>,
     *     rows: list<array<string, mixed>>
     * }
     */
    public static function operationsCenterPayload(): array
    {
        if (! self::tableExists()) {
            return ['last_24h' => 0, 'last_7d' => 0, 'by_code' => [], 'rows' => []];
        }

        $failed = StudioCompositionVideoExportJob::STATUS_FAILED;
        $last24h = StudioCompositionVideoExportJob::query()
            ->where('status', $failed)
            ->where('updated_at', '>=', now()->subDay())
            ->count();
        $last7d = StudioCompositionVideoExportJob::query()
            ->where('status', $failed)
            ->where('updated_at', '>=', now()->subDays(7))
            ->count();

        $byCode = StudioCompositionVideoExportJob::query()
            ->where('status', $failed)
            ->where('updated_at', '>=', now()->subDays(7))
            ->get(['error_json'])
            ->groupBy(fn ($r): string => (string) (($r->error_json['code'] ?? '') !== '' ? $r->error_json['code'] : 'unknown'))
            ->map->count()
            ->sortDesc()
            ->take(20);

        $byCodeList = [];
        foreach ($byCode as $code => $count) {
            $byCodeList[] = ['code' => (string) $code, 'count' => (int) $count];
        }

        $rows = StudioCompositionVideoExportJob::query()
            ->where('status', $failed)
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get(['id', 'tenant_id', 'composition_id', 'render_mode', 'user_id', 'error_json', 'updated_at']);

        $tenantIds = $rows->pluck('tenant_id')->unique()->filter()->values()->all();
        $tenants = $tenantIds === []
            ? collect()
            : Tenant::query()->whereIn('id', $tenantIds)->get(['id', 'name', 'slug'])->keyBy('id');

        $mapped = [];
        foreach ($rows as $row) {
            $err = is_array($row->error_json) ? $row->error_json : [];
            $debug = is_array($err['debug'] ?? null) ? $err['debug'] : [];
            $stderr = (string) ($debug['stderr_tail'] ?? '');
            $fc = (string) ($debug['filter_complex'] ?? '');
            $stderrTail = $stderr !== '' ? mb_substr($stderr, -4000) : '';
            $t = $tenants->get($row->tenant_id);
            $diagDetail = self::diagnosticsDetailForAdminPanel($debug);
            $mapped[] = [
                'id' => $row->id,
                'status' => (string) $row->status,
                'tenant_id' => $row->tenant_id,
                'tenant_name' => $t?->name,
                'tenant_slug' => $t?->slug,
                'composition_id' => $row->composition_id,
                'render_mode' => (string) ($row->render_mode ?? ''),
                'user_id' => $row->user_id,
                'error_code' => (string) ($err['code'] ?? ''),
                'error_message' => (string) ($err['message'] ?? ''),
                'exit_code' => isset($debug['exit_code']) ? (int) $debug['exit_code'] : null,
                'has_blend_graph' => str_contains($fc, 'blend=all_mode'),
                'stderr_preview' => \Illuminate\Support\Str::limit(trim($stderrTail !== '' ? $stderrTail : $stderr), 12_000),
                'diagnostics_detail' => $diagDetail,
                'updated_at' => $row->updated_at?->toIso8601String(),
            ];
        }

        return [
            'last_24h' => $last24h,
            'last_7d' => $last7d,
            'by_code' => $byCodeList,
            'rows' => $mapped,
        ];
    }

    public static function failureCountLast24Hours(): int
    {
        if (! self::tableExists()) {
            return 0;
        }

        return (int) StudioCompositionVideoExportJob::query()
            ->where('status', StudioCompositionVideoExportJob::STATUS_FAILED)
            ->where('updated_at', '>=', now()->subDay())
            ->count();
    }

    /**
     * Pretty JSON for the Operations Center detail row when FFmpeg stderr is empty (e.g. strict layer policy).
     *
     * @param  array<string, mixed>  $debug
     */
    private static function diagnosticsDetailForAdminPanel(array $debug): ?string
    {
        $chunks = [];
        if (isset($debug['layer_diagnostics']) && is_array($debug['layer_diagnostics'])) {
            $chunks[] = json_encode($debug['layer_diagnostics'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        if (isset($debug['errors']) && is_array($debug['errors'])) {
            $chunks[] = json_encode(['validation_errors' => $debug['errors']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        if (isset($debug['normalized_summary']) && is_array($debug['normalized_summary'])) {
            $chunks[] = json_encode($debug['normalized_summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        if ($chunks === []) {
            return null;
        }

        return \Illuminate\Support\Str::limit(implode("\n\n---\n\n", $chunks), 14_000);
    }
}
