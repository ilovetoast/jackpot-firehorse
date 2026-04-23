<?php

namespace App\Support;

use App\Models\StudioCompositionVideoExportJob;
use App\Services\Studio\StudioCompositionVideoExportRenderMode;

/**
 * Lightweight, read-only diagnostics for API / support (no secrets).
 */
final class StudioCanvasRuntimeExportJobDiagnostics
{
    /**
     * @return array<string, mixed>
     */
    public static function canvasRuntimeDebugBlock(StudioCompositionVideoExportJob $row): array
    {
        $meta = is_array($row->meta_json) ? $row->meta_json : [];
        $cap = is_array($meta['canvas_runtime_capture'] ?? null) ? $meta['canvas_runtime_capture'] : [];

        return [
            'export_phase' => self::humanExportPhase($row),
            'ffmpeg_merge_pending' => (bool) ($cap['ffmpeg_merge_pending'] ?? false),
            'capture_phase' => isset($cap['phase']) ? (string) $cap['phase'] : null,
            'has_canvas_runtime_diagnostics' => isset($meta['canvas_runtime_diagnostics']),
            'has_canvas_runtime_merge_diagnostics' => isset($meta['canvas_runtime_merge_diagnostics']),
            'has_canvas_runtime_repair' => isset($meta['canvas_runtime_repair']),
            'has_canvas_runtime_retention' => isset($meta['canvas_runtime_retention']),
        ];
    }

    public static function humanExportPhase(StudioCompositionVideoExportJob $row): string
    {
        if ($row->render_mode !== StudioCompositionVideoExportRenderMode::CANVAS_RUNTIME->value) {
            return 'n/a_not_canvas_runtime';
        }

        $meta = is_array($row->meta_json) ? $row->meta_json : [];
        $cap = is_array($meta['canvas_runtime_capture'] ?? null) ? $meta['canvas_runtime_capture'] : [];
        $pending = (bool) ($cap['ffmpeg_merge_pending'] ?? false);
        $capPhase = (string) ($cap['phase'] ?? '');

        if ($row->status === StudioCompositionVideoExportJob::STATUS_COMPLETE && $pending) {
            return 'inconsistent_complete_merge_pending';
        }

        if ($row->status === StudioCompositionVideoExportJob::STATUS_COMPLETE && $row->output_asset_id !== null) {
            return 'complete';
        }

        if ($row->status === StudioCompositionVideoExportJob::STATUS_FAILED) {
            return 'failed';
        }

        if ($row->status === StudioCompositionVideoExportJob::STATUS_QUEUED) {
            return 'queued';
        }

        if ($row->status === StudioCompositionVideoExportJob::STATUS_PROCESSING) {
            if ($capPhase === 'frames_captured' && $pending) {
                return 'processing_merge';
            }
            if ($capPhase === 'merge_failed') {
                return 'processing_merge_failed';
            }

            return 'processing_capture_or_merge';
        }

        return 'unknown';
    }
}
