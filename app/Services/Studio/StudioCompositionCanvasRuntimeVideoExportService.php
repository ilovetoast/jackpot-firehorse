<?php

namespace App\Services\Studio;

use App\Contracts\StudioCanvasRuntimePlaywrightInvokerContract;
use App\Models\Asset;
use App\Models\Composition;
use App\Models\StudioCompositionVideoExportJob;
use App\Models\Tenant;
use App\Models\User;
use App\Support\EditorAssetOriginalBytesLoader;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

/**
 * Canvas-runtime composition export: signed internal render URL → Playwright PNG sequence → FFmpeg merge
 * over the legacy-trimmed base video → MP4 publish (same durable path as {@see StudioCompositionVideoExportService}).
 */
final class StudioCompositionCanvasRuntimeVideoExportService
{
    public function __construct(
        private StudioCanvasRuntimePlaywrightInvokerContract $playwrightInvoker,
        private StudioCompositionCanvasRuntimeFfmpegMerger $merger,
        private StudioCompositionVideoExportMp4Publisher $mp4Publisher,
    ) {}

    public function run(StudioCompositionVideoExportJob $row, Tenant $tenant, User $user): void
    {
        if (! (bool) config('studio_video.canvas_runtime_export_enabled', false)) {
            $this->fail(
                $row,
                'canvas_runtime_export_disabled',
                'Canvas runtime export is disabled on this environment. Set STUDIO_VIDEO_CANVAS_RUNTIME_EXPORT_ENABLED=true after workers are configured (Playwright + fonts + Node).',
                ['hint' => 'Do not fall back to legacy_bitmap unless the client explicitly requests render_mode=legacy_bitmap.']
            );

            return;
        }

        $composition = Composition::query()
            ->where('id', $row->composition_id)
            ->where('tenant_id', $tenant->id)
            ->first();
        if (! $composition) {
            $this->fail($row, 'composition_not_found', 'Composition missing for export job.', []);

            return;
        }

        $preserved = is_array($row->meta_json) ? $row->meta_json : [];

        $row->update(['status' => StudioCompositionVideoExportJob::STATUS_PROCESSING]);

        $payload = CompositionRenderPayloadFactory::fromComposition($composition, $tenant, $user, $row);
        $ttl = max(5, (int) config('studio_video.canvas_export_render_url_ttl_minutes', 120));
        $renderUrl = URL::temporarySignedRoute(
            'internal.studio.composition-export-render',
            now()->addMinutes($ttl),
            ['exportJob' => $row->id],
        );

        $workDir = storage_path('app/studio-canvas-runtime/'.$row->id.'/run-'.Str::lower(Str::random(12)));
        File::ensureDirectoryExists($workDir);

        $scriptPath = $this->resolvePlaywrightScriptPath();
        $nodeBinary = (string) config('studio_video.canvas_export_node_binary', 'node');
        $command = [
            $nodeBinary,
            $scriptPath,
            '--url='.$renderUrl,
            '--output-dir='.$workDir,
            '--fps='.(string) (int) ($payload['fps'] ?? 30),
            '--duration-ms='.(string) (int) ($payload['duration_ms'] ?? 30_000),
            '--width='.(string) (int) ($payload['width'] ?? 1080),
            '--height='.(string) (int) ($payload['height'] ?? 1080),
            '--export-job-id='.(string) $row->id,
            '--readiness-timeout-ms='.(string) (int) config('studio_video.canvas_export_readiness_timeout_ms', 120_000),
            '--navigation-timeout-ms='.(string) (int) config('studio_video.canvas_export_navigation_timeout_ms', 120_000),
            '--frame-settle-ms='.(string) (int) config('studio_video.canvas_export_frame_settle_ms', 50),
            '--device-scale-factor='.(string) (float) config('studio_video.canvas_export_device_scale_factor', 1.0),
        ];

        $timeoutSeconds = max(60, (int) config('studio_video.canvas_export_capture_timeout_seconds', 7200));
        $cwd = base_path();

        $preRunDiagnostics = [
            'export_mode' => StudioCompositionVideoExportRenderMode::CANVAS_RUNTIME->value,
            'implementation' => 'canvas_runtime_playwright_capture_v1',
            'render_contract_version' => $payload['version'] ?? null,
            'render_page_url_host' => parse_url($renderUrl, PHP_URL_HOST),
            'render_url_ttl_minutes' => $ttl,
            'fps' => $payload['fps'] ?? null,
            'duration_ms' => $payload['duration_ms'] ?? null,
            'composition_id' => (string) $composition->id,
            'working_directory' => $workDir,
            'playwright_script' => $scriptPath,
            'node_binary' => $nodeBinary,
        ];

        try {
            $result = $this->playwrightInvoker->run($command, $cwd, $timeoutSeconds);
        } catch (Throwable $e) {
            $this->fail($row, 'canvas_runtime_playwright_process_exception', $e->getMessage(), array_merge($preRunDiagnostics, [
                'exception_class' => $e::class,
            ]), $preserved, $workDir);

            return;
        }

        $exitCode = $result['exitCode'];
        $stderr = $result['stderr'];
        $stdout = $result['stdout'];

        if ($exitCode !== 0) {
            $fromDisk = $this->readOptionalJsonFile($workDir.'/capture-diagnostics.json');
            $humanMessage = $this->buildPlaywrightCaptureFailureMessage((int) $exitCode, $stderr, $fromDisk);
            $this->fail($row, 'canvas_runtime_playwright_failed', $humanMessage, array_merge($preRunDiagnostics, [
                'exit_code' => $exitCode,
                'exit_code_hint' => self::playwrightExitCodeHintLine((int) $exitCode),
                'stderr_tail' => mb_substr($stderr, -8000),
                'stdout_tail' => mb_substr($stdout, -2000),
                'capture_diagnostics_file' => $fromDisk,
            ]), $preserved, $workDir);

            return;
        }

        $manifestPath = $workDir.DIRECTORY_SEPARATOR.'capture-manifest.json';
        if (! is_file($manifestPath)) {
            $this->fail($row, 'canvas_runtime_manifest_missing', 'capture-manifest.json was not written by the capture script.', array_merge($preRunDiagnostics, [
                'exit_code' => $exitCode,
                'stderr_tail' => mb_substr($stderr, -8000),
            ]), $preserved, $workDir);

            return;
        }

        try {
            /** @var array<string, mixed> $manifest */
            $manifest = json_decode(File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->fail($row, 'canvas_runtime_manifest_invalid', 'Could not parse capture-manifest.json.', array_merge($preRunDiagnostics, [
                'json_error' => $e->getMessage(),
            ]), $preserved, $workDir);

            return;
        }

        $manifest = $this->redactSignedUrlsInManifest($manifest);

        $canvasDiagnostics = array_merge([
            'schema' => 'studio_canvas_runtime_capture_diagnostics_v1',
            'phase' => 'capture_complete',
        ], $preRunDiagnostics, [
            'completed_at' => now()->toIso8601String(),
            'manifest' => $manifest,
            'stderr_tail' => mb_substr($stderr, -4000),
            'stdout_tail' => mb_substr($stdout, -2000),
        ]);

        Log::info('[StudioCompositionCanvasRuntimeVideoExportService] capture complete', [
            'export_job_id' => $row->id,
            'tenant_id' => $tenant->id,
            'frames' => $manifest['total_captured_frames'] ?? null,
            'working_directory' => $workDir,
        ]);

        $metaAfterCapture = array_merge($preserved, [
            'canvas_runtime_diagnostics' => $canvasDiagnostics,
            'canvas_runtime_capture' => [
                'phase' => 'frames_captured',
                'ffmpeg_merge_pending' => true,
                'working_directory' => $workDir,
                'manifest_path' => $manifestPath,
                'total_captured_frames' => $manifest['total_captured_frames'] ?? null,
            ],
        ]);
        $row->update([
            'status' => StudioCompositionVideoExportJob::STATUS_PROCESSING,
            'error_json' => null,
            'meta_json' => $metaAfterCapture,
        ]);

        $this->performMergeAndPublishPhase(
            $row,
            $composition,
            $tenant,
            $user,
            $manifest,
            $workDir,
            $metaAfterCapture,
            null,
        );
    }

    /**
     * Conservative merge+publish repair (no Playwright re-run). Intended for artisan reconcile with --execute only.
     *
     * @return array{ok: bool, classification?: string, message?: string, reason?: string}
     */
    public function repairMergePublish(StudioCompositionVideoExportJob $row, Tenant $tenant, User $user): array
    {
        $classification = StudioCanvasRuntimeExportJobClassifier::classify($row);
        $repairable = [
            StudioCanvasRuntimeExportJobClassifier::REPAIRABLE_STUCK_COMPLETE_MERGE_PENDING,
            StudioCanvasRuntimeExportJobClassifier::REPAIRABLE_PROCESSING_MERGE_PENDING,
        ];
        if (! in_array($classification, $repairable, true)) {
            return [
                'ok' => false,
                'classification' => $classification,
                'message' => 'Row is not classified as safely repairable (merge-only).',
            ];
        }

        if (! (bool) config('studio_video.canvas_runtime_export_enabled', false)) {
            return [
                'ok' => false,
                'classification' => $classification,
                'message' => 'canvas_runtime_export_enabled is false; refusing repair on this environment.',
            ];
        }

        $row->refresh();
        $meta = is_array($row->meta_json) ? $row->meta_json : [];
        $cap = is_array($meta['canvas_runtime_capture'] ?? null) ? $meta['canvas_runtime_capture'] : [];
        $manifestPath = isset($cap['manifest_path']) ? (string) $cap['manifest_path'] : '';
        $workDir = isset($cap['working_directory']) ? (string) $cap['working_directory'] : '';

        $quick = StudioCanvasRuntimeExportJobClassifier::validateManifestQuick($manifestPath);
        if (! $quick['ok']) {
            return [
                'ok' => false,
                'classification' => $classification,
                'message' => 'Manifest on disk failed quick validation.',
                'reason' => $quick['reason'] ?? 'unknown',
            ];
        }

        try {
            /** @var array<string, mixed> $manifest */
            $manifest = json_decode(File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'classification' => $classification,
                'message' => 'Could not re-read manifest JSON: '.$e->getMessage(),
            ];
        }

        $manifest = $this->redactSignedUrlsInManifest($manifest);

        $composition = Composition::query()
            ->where('id', $row->composition_id)
            ->where('tenant_id', $tenant->id)
            ->first();
        if (! $composition) {
            return [
                'ok' => false,
                'classification' => $classification,
                'message' => 'Composition missing for repair.',
            ];
        }

        if ($row->status === StudioCompositionVideoExportJob::STATUS_COMPLETE) {
            $row->update(['status' => StudioCompositionVideoExportJob::STATUS_PROCESSING]);
            $row->refresh();
        }

        $metaAfterCapture = is_array($row->meta_json) ? $row->meta_json : [];

        $this->performMergeAndPublishPhase(
            $row->fresh(),
            $composition,
            $tenant,
            $user,
            $manifest,
            $workDir,
            $metaAfterCapture,
            $classification,
        );

        $outcome = StudioCompositionVideoExportJob::query()->find($row->id);

        return [
            'ok' => $outcome !== null && $outcome->status === StudioCompositionVideoExportJob::STATUS_COMPLETE,
            'classification' => $classification,
            'message' => $outcome !== null && $outcome->status === StudioCompositionVideoExportJob::STATUS_COMPLETE
                ? 'Repair completed successfully.'
                : 'Repair did not reach complete status (see job error_json / merge diagnostics).',
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $metaAfterCapture
     * @param  non-empty-string|null  $repairClassification  set when invoked from {@see repairMergePublish}
     */
    private function performMergeAndPublishPhase(
        StudioCompositionVideoExportJob $row,
        Composition $composition,
        Tenant $tenant,
        User $user,
        array $manifest,
        string $workDir,
        array $metaAfterCapture,
        ?string $repairClassification,
    ): void {
        $requestMeta = $metaAfterCapture;
        $includeAudioPref = $requestMeta['include_audio'] ?? true;
        $includeAudioPref = is_bool($includeAudioPref) ? $includeAudioPref : (bool) $includeAudioPref;

        $doc = is_array($composition->document_json) ? $composition->document_json : [];
        $w = (int) ($doc['width'] ?? 0);
        $h = (int) ($doc['height'] ?? 0);
        $layers = is_array($doc['layers'] ?? null) ? $doc['layers'] : [];
        $videoLayer = StudioCompositionVideoExportMediaHelper::selectPrimaryVideoLayer($layers);
        if ($videoLayer === null || $w < 2 || $h < 2) {
            $this->failMerge($row, $metaAfterCapture, $workDir, 'canvas_runtime_merge_no_video_layer', 'Composition needs a valid canvas and primary video layer for merge (legacy export rules).', [
                'canvas_width' => $w,
                'canvas_height' => $h,
            ]);

            return;
        }

        $assetId = (string) $videoLayer['assetId'];
        $videoAsset = Asset::query()
            ->where('id', $assetId)
            ->where('tenant_id', $tenant->id)
            ->first();
        if (! $videoAsset) {
            $this->failMerge($row, $metaAfterCapture, $workDir, 'canvas_runtime_merge_video_asset_missing', 'Primary video asset not found for merge.', ['asset_id' => $assetId]);

            return;
        }

        $ver = $videoAsset->currentVersion()->first();
        $rel = $ver?->file_path ?? $videoAsset->file_path;
        if (! is_string($rel) || $rel === '') {
            $this->failMerge($row, $metaAfterCapture, $workDir, 'canvas_runtime_merge_video_path_missing', 'Primary video asset has no file path.', ['asset_id' => $assetId]);

            return;
        }

        $tmpBase = storage_path('app/tmp/'.Str::random(20).'_canvas_src.mp4');
        @mkdir(dirname($tmpBase), 0775, true);
        $tmpOut = null;

        try {
            try {
                $raw = EditorAssetOriginalBytesLoader::loadFromStorage($videoAsset, $rel);
            } catch (\InvalidArgumentException $e) {
                $this->failMerge($row, $metaAfterCapture, $workDir, 'canvas_runtime_merge_base_video_unreadable', 'Could not read base video from storage.', [
                    'asset_id' => $assetId,
                    'message' => $e->getMessage(),
                ]);

                return;
            }
            file_put_contents($tmpBase, $raw);

            $ffprobe = (string) config('studio_video.ffprobe_binary', 'ffprobe');
            $probedS = StudioCompositionVideoExportMediaHelper::ffprobeDurationSeconds($ffprobe, $tmpBase);
            if ($probedS <= 0) {
                $this->failMerge($row, $metaAfterCapture, $workDir, 'canvas_runtime_merge_ffprobe_duration', 'Could not read base video duration (ffprobe).', []);

                return;
            }

            $timing = StudioCompositionVideoExportMediaHelper::computeTrimAndOutputDuration($doc, $videoLayer, $probedS);
            $outDurS = $timing['output_duration_s'];
            $trimInS = $timing['trim_in_s'];
            $tl = is_array($videoLayer['timeline'] ?? null) ? $videoLayer['timeline'] : [];
            $tlMuted = (bool) ($tl['muted'] ?? false);
            $hasAudio = StudioCompositionVideoExportMediaHelper::ffprobeHasAudio($ffprobe, $tmpBase);
            $wantAudio = $includeAudioPref && ! $tlMuted && $hasAudio;
            $videoZ = (int) ($videoLayer['z'] ?? 0);
            $padColor = StudioCompositionVideoExportMediaHelper::resolvePadColorForFfmpeg($layers, $videoZ);

            $merge = $this->merger->mergeToTempMp4(
                $workDir,
                $manifest,
                $tmpBase,
                $trimInS,
                $outDurS,
                $w,
                $h,
                $padColor,
                $wantAudio,
            );

            if (! $merge['ok'] || ! isset($merge['local_mp4_path'])) {
                $this->failMerge($row, $metaAfterCapture, $workDir, 'canvas_runtime_merge_ffmpeg_failed', 'FFmpeg merge failed or produced no output.', [
                    'merge_diagnostics' => $merge['diagnostics'] ?? [],
                ]);

                return;
            }

            $tmpOut = (string) $merge['local_mp4_path'];

            $technical = [
                'export_render_mode' => StudioCompositionVideoExportRenderMode::CANVAS_RUNTIME->value,
                'include_audio' => $includeAudioPref,
                'audio_muxed' => $wantAudio,
                'layer_timeline_muted' => $tlMuted,
                'ffmpeg_pad_color' => $padColor,
                'ffmpeg_command_summary' => 'canvas_runtime: base(scale+pad trim) + png overlay(shortest) + libx264',
                'primary_video_layer_id' => (string) ($videoLayer['id'] ?? ''),
                'probed_source_duration_s' => $probedS,
                'trim_in_ms' => $timing['trim_in_ms'],
                'trim_out_ms' => $timing['trim_out_ms'],
                'output_duration_cap_s' => $outDurS,
                'composition_duration_ms' => $timing['composition_duration_ms'] > 0 ? $timing['composition_duration_ms'] : null,
                'canvas_runtime_captured_frames' => (int) ($manifest['total_captured_frames'] ?? 0),
                'canvas_runtime_manifest_fps' => (int) ($manifest['fps'] ?? 0),
            ];

            try {
                $published = $this->mp4Publisher->publish($row, $composition, $tenant, $user, $tmpOut, $w, $h, $technical);
            } catch (Throwable $e) {
                if (is_string($tmpOut) && $tmpOut !== '' && is_file($tmpOut)) {
                    @unlink($tmpOut);
                }
                $this->failMerge($row, $metaAfterCapture, $workDir, 'canvas_runtime_publish_failed', 'MP4 publish step failed after merge.', [
                    'exception_class' => $e::class,
                    'message' => $e->getMessage(),
                ]);

                return;
            }

            @unlink($tmpOut);

            $mergeDiag = is_array($merge['diagnostics'] ?? null) ? $merge['diagnostics'] : [];

            $pngCleanup = (bool) config('studio_video.canvas_runtime_merge_delete_png_frames_after_success', false);

            $retention = [
                'schema' => 'studio_canvas_runtime_retention_v1',
                'working_directory' => $workDir,
                'png_frames_deleted_after_success' => false,
                'manifest_and_capture_diagnostics_retained_in_meta_json' => true,
                'on_failure_working_directory_retained' => true,
                'policy_note' => (string) config('studio_video.canvas_runtime_retention_policy_note', ''),
            ];

            $captureBlock = array_merge(
                is_array($metaAfterCapture['canvas_runtime_capture'] ?? null) ? $metaAfterCapture['canvas_runtime_capture'] : [],
                [
                    'phase' => 'complete',
                    'ffmpeg_merge_pending' => false,
                ]
            );

            $finalMeta = array_merge($metaAfterCapture, $published['technical'], [
                'canvas_runtime_merge_diagnostics' => $mergeDiag,
                'canvas_runtime_capture' => $captureBlock,
                'canvas_runtime_retention' => $retention,
            ]);

            if ($repairClassification !== null) {
                $finalMeta['canvas_runtime_repair'] = [
                    'schema' => 'studio_canvas_runtime_repair_v1',
                    'repaired_at' => now()->toIso8601String(),
                    'repair_classification' => $repairClassification,
                    'repair_context' => 'merge_only_repair',
                ];
            }

            $row->update([
                'status' => StudioCompositionVideoExportJob::STATUS_COMPLETE,
                'error_json' => null,
                'meta_json' => $finalMeta,
                'output_asset_id' => $published['asset']->id,
            ]);

            if ($pngCleanup) {
                $this->deleteCapturedPngFrames($workDir);
                $row->refresh();
                $m = is_array($row->meta_json) ? $row->meta_json : [];
                if (isset($m['canvas_runtime_retention']) && is_array($m['canvas_runtime_retention'])) {
                    $m['canvas_runtime_retention']['png_frames_deleted_after_success'] = true;
                    $row->update(['meta_json' => $m]);
                }
            }

            Log::info('[StudioCompositionCanvasRuntimeVideoExportService] merge and publish complete', [
                'export_job_id' => $row->id,
                'output_asset_id' => $published['asset']->id,
                'repair' => $repairClassification !== null,
            ]);
        } catch (Throwable $e) {
            if (is_string($tmpOut) && $tmpOut !== '' && is_file($tmpOut)) {
                @unlink($tmpOut);
            }
            $this->failMerge($row, $metaAfterCapture, $workDir, 'canvas_runtime_merge_exception', $e->getMessage(), [
                'exception_class' => $e::class,
            ]);
        } finally {
            if (is_file($tmpBase)) {
                @unlink($tmpBase);
            }
        }
    }

    private function deleteCapturedPngFrames(string $workDir): void
    {
        $glob = rtrim($workDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'frame_*.png';
        foreach (File::glob($glob) ?: [] as $f) {
            if (is_string($f) && is_file($f)) {
                @unlink($f);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $metaAfterCapture
     * @param  array<string, mixed>  $debug
     */
    private function failMerge(
        StudioCompositionVideoExportJob $row,
        array $metaAfterCapture,
        string $workDir,
        string $code,
        string $message,
        array $debug,
    ): void {
        Log::warning('[StudioCompositionCanvasRuntimeVideoExportService] merge failed', [
            'export_job_id' => $row->id,
            'code' => $code,
        ]);

        $capture = is_array($metaAfterCapture['canvas_runtime_capture'] ?? null) ? $metaAfterCapture['canvas_runtime_capture'] : [];
        $capture['phase'] = 'merge_failed';
        $capture['ffmpeg_merge_pending'] = false;

        $row->update([
            'status' => StudioCompositionVideoExportJob::STATUS_FAILED,
            'error_json' => [
                'code' => $code,
                'failure_code' => $code,
                'message' => $message,
                'debug' => array_merge($debug, [
                    'working_directory' => $workDir,
                    'phase' => 'merge_failed',
                ]),
            ],
            'meta_json' => array_merge($metaAfterCapture, [
                'canvas_runtime_capture' => $capture,
                'canvas_runtime_merge_diagnostics' => array_merge([
                    'schema' => 'studio_canvas_runtime_merge_diagnostics_v1',
                    'phase' => 'merge_failed',
                    'failed_at' => now()->toIso8601String(),
                    'failure_code' => $code,
                ], $debug),
                'canvas_runtime_retention' => [
                    'schema' => 'studio_canvas_runtime_retention_v1',
                    'working_directory' => $workDir,
                    'png_frames_deleted_after_failure' => false,
                    'policy_note' => 'Artifacts retained on merge/publish failure for debugging.',
                ],
            ]),
        ]);
    }

    private function resolvePlaywrightScriptPath(): string
    {
        $raw = trim((string) config('studio_video.canvas_export_playwright_script', 'scripts/studio-canvas-export.mjs'));
        if ($raw === '') {
            return base_path('scripts/studio-canvas-export.mjs');
        }
        if ($raw[0] === '/' || preg_match('#^[a-zA-Z]:[\\\\/]#', $raw) === 1) {
            return $raw;
        }

        return base_path($raw);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function redactSignedUrlsInManifest(array $manifest): array
    {
        if (isset($manifest['render_url']) && is_string($manifest['render_url'])) {
            $manifest['render_url_host'] = parse_url($manifest['render_url'], PHP_URL_HOST);
            unset($manifest['render_url']);
        }

        if (isset($manifest['bridge_snapshots']) && is_array($manifest['bridge_snapshots'])) {
            foreach ($manifest['bridge_snapshots'] as $i => $snap) {
                if (! is_array($snap)) {
                    continue;
                }
                $st = $snap['state'] ?? null;
                if (is_array($st) && array_key_exists('lastError', $st) && is_string($st['lastError']) && str_contains($st['lastError'], 'http')) {
                    $manifest['bridge_snapshots'][$i]['state']['lastError'] = mb_substr($st['lastError'], 0, 240);
                }
            }
        }

        return $manifest;
    }

    private function readOptionalJsonFile(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }
        try {
            /** @var array<string, mixed> */
            return json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Maps {@see scripts/studio-canvas-export.mjs} exit codes (header comment) to a short support hint.
     */
    private static function playwrightExitCodeHintLine(int $exitCode): string
    {
        return match ($exitCode) {
            2 => 'exit_2_bad_args: invalid CLI flags, paths, or numeric parameters.',
            3 => 'exit_3_navigation: Chromium could not load the signed render URL (timeouts, DNS/TLS, or worker cannot reach APP_URL).',
            4 => 'exit_4_readiness: page loaded but export bridge never became ready=true (fonts, assets, raster preload, or readiness timeout).',
            5 => 'exit_5_capture: screenshot loop failed (missing [data-jp-composition-scene-root], Playwright error, or total-timeout-ms).',
            6 => 'exit_6_manifest_io: manifest write failed (disk permissions or path).',
            default => 'nonzero_exit: see stderr_tail and capture-diagnostics.json on the worker.',
        };
    }

    /**
     * @param  array<string, mixed>|null  $captureDiagnosticsFile
     */
    private function buildPlaywrightCaptureFailureMessage(int $exitCode, string $stderr, ?array $captureDiagnosticsFile): string
    {
        $detail = self::parseLastJsonErrorFromStderr($stderr);
        if ($detail === '' && is_array($captureDiagnosticsFile)) {
            $e = $captureDiagnosticsFile['error'] ?? null;
            $detail = is_string($e) ? $e : '';
        }
        $hint = match ($exitCode) {
            2 => 'The Node driver rejected its arguments.',
            3 => 'The headless browser could not open the signed export URL before navigation-timeout-ms.',
            4 => 'The export page did not become ready before readiness-timeout-ms (fonts, images, or bridge checks).',
            5 => 'Frame capture failed after the page was ready.',
            6 => 'Writing capture-manifest.json or diagnostics failed.',
            default => 'The Playwright capture process ended unsuccessfully.',
        };
        $msg = "Studio video export: {$hint} (process exit code {$exitCode}).";
        if ($detail !== '') {
            $msg .= ' '.$detail;
        }
        $msg .= ' On the Horizon host: ensure `npm ci` and `npx playwright install --with-deps chromium` from the app root, and that workers can HTTP(S) reach the same host the signed URL uses. Inspect `error_json.debug` for stderr_tail.';

        return $msg;
    }

    private static function parseLastJsonErrorFromStderr(string $stderr): string
    {
        $lines = preg_split("/\r\n|\n|\r/", $stderr) ?: [];
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            if ($line === '' || ! str_starts_with($line, '{')) {
                continue;
            }
            try {
                /** @var array<string, mixed> $j */
                $j = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (isset($j['error']) && is_string($j['error']) && $j['error'] !== '') {
                    return $j['error'];
                }
            } catch (JsonException) {
                continue;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $debug
     * @param  array<string, mixed>  $preservedMeta
     */
    private function fail(
        StudioCompositionVideoExportJob $row,
        string $code,
        string $message,
        array $debug,
        array $preservedMeta = [],
        ?string $workDir = null,
    ): void {
        $diskExtras = [];
        if ($workDir !== null) {
            $diskExtras['capture_diagnostics_file'] = $this->readOptionalJsonFile($workDir.'/capture-diagnostics.json');
            $diskExtras['working_directory'] = $workDir;
        }

        Log::warning('[StudioCompositionCanvasRuntimeVideoExportService] capture failed', [
            'export_job_id' => $row->id,
            'code' => $code,
        ]);

        $row->update([
            'status' => StudioCompositionVideoExportJob::STATUS_FAILED,
            'error_json' => [
                'code' => $code,
                'failure_code' => $code,
                'message' => $message,
                'debug' => array_merge($debug, $diskExtras, [
                    'phase' => 'capture_failed',
                ]),
            ],
            'meta_json' => array_merge($preservedMeta ?: (is_array($row->meta_json) ? $row->meta_json : []), [
                'canvas_runtime_diagnostics' => array_merge([
                    'schema' => 'studio_canvas_runtime_capture_diagnostics_v1',
                    'phase' => 'capture_failed',
                    'failure_code' => $code,
                    'failed_at' => now()->toIso8601String(),
                ], $debug, $diskExtras),
            ]),
        ]);
    }
}
