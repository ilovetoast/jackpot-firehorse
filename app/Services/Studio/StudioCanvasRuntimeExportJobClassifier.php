<?php

namespace App\Services\Studio;

use App\Models\StudioCompositionVideoExportJob;
use Illuminate\Support\Facades\File;

/**
 * Conservative classification for canvas_runtime export jobs (reconciler / dry-run).
 * Prefers "ambiguous" or "skipped" over risky auto-repair when signals conflict.
 */
final class StudioCanvasRuntimeExportJobClassifier
{
    public const REPAIRABLE_STUCK_COMPLETE_MERGE_PENDING = 'repairable_stuck_complete_merge_pending';

    public const REPAIRABLE_PROCESSING_MERGE_PENDING = 'repairable_processing_merge_pending';

    public const AMBIGUOUS_COMPLETE_MERGE_PENDING_WITH_OUTPUT = 'ambiguous_complete_merge_pending_with_output';

    public const AMBIGUOUS_COMPLETE_MERGE_PENDING_MISSING_ARTIFACTS = 'ambiguous_complete_merge_pending_missing_artifacts';

    public const AMBIGUOUS_PROCESSING_MERGE_PENDING_MISSING_ARTIFACTS = 'ambiguous_processing_merge_pending_missing_artifacts';

    public const SKIPPED_NOT_CANVAS_RUNTIME = 'skipped_not_canvas_runtime';

    public const SKIPPED_NO_MERGE_PENDING = 'skipped_no_merge_pending';

    public const SKIPPED_NO_ACTION_NEEDED = 'skipped_no_action_needed';

    /**
     * Classify a row for reconcile tooling. Never mutates the model.
     */
    public static function classify(StudioCompositionVideoExportJob $row): string
    {
        if ($row->render_mode !== StudioCompositionVideoExportRenderMode::CANVAS_RUNTIME->value) {
            return self::SKIPPED_NOT_CANVAS_RUNTIME;
        }

        $meta = is_array($row->meta_json) ? $row->meta_json : [];
        $cap = is_array($meta['canvas_runtime_capture'] ?? null) ? $meta['canvas_runtime_capture'] : [];
        $pending = (bool) ($cap['ffmpeg_merge_pending'] ?? false);
        if (! $pending) {
            return self::SKIPPED_NO_MERGE_PENDING;
        }

        $workDir = isset($cap['working_directory']) ? (string) $cap['working_directory'] : '';
        $manifestPath = isset($cap['manifest_path']) ? (string) $cap['manifest_path'] : '';
        $artifactsOk = self::captureArtifactsLookUsable($workDir, $manifestPath);

        if ($row->status === StudioCompositionVideoExportJob::STATUS_COMPLETE) {
            if ($row->output_asset_id !== null) {
                return self::AMBIGUOUS_COMPLETE_MERGE_PENDING_WITH_OUTPUT;
            }
            if ($artifactsOk) {
                return self::REPAIRABLE_STUCK_COMPLETE_MERGE_PENDING;
            }

            return self::AMBIGUOUS_COMPLETE_MERGE_PENDING_MISSING_ARTIFACTS;
        }

        if ($row->status === StudioCompositionVideoExportJob::STATUS_PROCESSING) {
            if ($artifactsOk) {
                return self::REPAIRABLE_PROCESSING_MERGE_PENDING;
            }

            return self::AMBIGUOUS_PROCESSING_MERGE_PENDING_MISSING_ARTIFACTS;
        }

        return self::SKIPPED_NO_ACTION_NEEDED;
    }

    public static function captureArtifactsLookUsable(string $workDir, string $manifestPath): bool
    {
        if ($workDir === '' || $manifestPath === '' || ! is_dir($workDir) || ! is_file($manifestPath)) {
            return false;
        }
        $wdReal = realpath($workDir);
        $manifestDirReal = realpath(dirname($manifestPath));
        if ($wdReal === false || $manifestDirReal === false) {
            return false;
        }

        return $wdReal === $manifestDirReal;
    }

    /**
     * @return array{ok: bool, reason?: string}
     */
    public static function validateManifestQuick(string $manifestPath): array
    {
        if (! is_file($manifestPath)) {
            return ['ok' => false, 'reason' => 'manifest_file_missing'];
        }
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode(File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => 'manifest_json_invalid'];
        }
        $n = (int) ($decoded['total_captured_frames'] ?? 0);
        if ($n < 1) {
            return ['ok' => false, 'reason' => 'manifest_zero_frames'];
        }
        $pattern = (string) ($decoded['frame_filename_pattern'] ?? '');
        if ($pattern === '') {
            return ['ok' => false, 'reason' => 'manifest_missing_frame_pattern'];
        }

        return ['ok' => true];
    }
}
