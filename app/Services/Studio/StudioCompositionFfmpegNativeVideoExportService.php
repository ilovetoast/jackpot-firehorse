<?php

namespace App\Services\Studio;

use App\Models\Asset;
use App\Models\Composition;
use App\Models\StudioCompositionVideoExportJob;
use App\Models\Tenant;
use App\Models\User;
use App\Studio\Rendering\CompositionRenderNormalizer;
use App\Studio\Rendering\CompositionRenderValidator;
use App\Studio\Rendering\Dto\CompositionRenderRequest;
use App\Studio\Rendering\Dto\RenderLayer;
use App\Studio\Rendering\FfmpegNativeCompositionRenderer;
use App\Studio\Rendering\RenderAssetStager;
use App\Studio\Rendering\RenderWorkspace;
use App\Studio\Rendering\Exceptions\StudioFontResolutionException;
use App\Studio\Rendering\TextOverlayRasterizer;
use Illuminate\Support\Facades\Log;

/**
 * Full-scene Studio export without Playwright: normalize → stage → FFmpeg filter graph → publish.
 */
final class StudioCompositionFfmpegNativeVideoExportService
{
    public function __construct(
        private CompositionRenderNormalizer $normalizer,
        private RenderAssetStager $stager,
        private TextOverlayRasterizer $textRasterizer,
        private CompositionRenderValidator $validator,
        private FfmpegNativeCompositionRenderer $renderer,
        private StudioCompositionVideoExportMp4Publisher $mp4Publisher,
    ) {}

    public function run(StudioCompositionVideoExportJob $row, Tenant $tenant, User $user): void
    {
        if (! (bool) config('studio_video.export_enabled', true)) {
            $this->fail($row, 'export_disabled', 'Video export is disabled.', []);

            return;
        }

        $row->update(['status' => StudioCompositionVideoExportJob::STATUS_PROCESSING]);

        $composition = Composition::query()
            ->where('id', $row->composition_id)
            ->where('tenant_id', $tenant->id)
            ->first();
        if (! $composition) {
            $this->fail($row, 'composition_not_found', 'Composition missing for export job.', []);

            return;
        }

        if (! StudioCompositionFfmpegNativeFeaturePolicy::isSupported($composition)) {
            $codes = StudioCompositionFfmpegNativeFeaturePolicy::unsupportedCodes($composition);
            $this->fail($row, 'ffmpeg_native_unsupported_features', StudioCompositionFfmpegNativeFeaturePolicy::humanSummary($codes), [
                'unsupported_codes' => $codes,
            ]);

            return;
        }

        $workspace = null;
        $preserved = is_array($row->meta_json) ? $row->meta_json : [];
        $includeAudio = (bool) ($preserved['include_audio'] ?? true);

        try {
            $workspace = RenderWorkspace::allocate($row->id);
            $doc = is_array($composition->document_json) ? $composition->document_json : [];
            $w = (int) ($doc['width'] ?? 0);
            $h = (int) ($doc['height'] ?? 0);
            $layers = is_array($doc['layers'] ?? null) ? $doc['layers'] : [];
            if (self::documentHasVisibleGradientFill($layers)) {
                Log::notice('[StudioCompositionFfmpegNativeVideoExportService] gradient fill layer present; V1 uses solid pad color from gradientEndColor/gradientStartColor/color (same rule as legacy FFmpeg)', [
                    'composition_id' => $composition->id,
                    'export_job_id' => $row->id,
                ]);
            }
            $videoLayer = StudioCompositionVideoExportMediaHelper::selectPrimaryVideoLayer($layers);
            if ($videoLayer === null || $w < 2 || $h < 2) {
                $this->fail($row, 'invalid_composition', 'Composition needs a valid canvas and at least one video layer.', [], $workspace);

                return;
            }

            $assetId = (string) ($videoLayer['assetId'] ?? '');
            $asset = Asset::query()->where('id', $assetId)->where('tenant_id', $tenant->id)->first();
            if (! $asset) {
                $this->fail($row, 'video_asset_missing', 'Video asset not found.', [], $workspace);

                return;
            }
            $ver = $asset->currentVersion()->first();
            $rel = $ver?->file_path ?? $asset->file_path;
            if (! is_string($rel) || $rel === '') {
                $this->fail($row, 'video_asset_no_path', 'Video asset has no file path.', [], $workspace);

                return;
            }

            $primaryLocal = $this->stager->stagePrimaryVideo($asset, $rel, $workspace);
            $ffprobe = trim((string) config('studio_rendering.ffprobe_binary', ''));
            if ($ffprobe === '') {
                $ffprobe = (string) config('studio_video.ffprobe_binary', 'ffprobe');
            }
            $probedS = StudioCompositionVideoExportMediaHelper::ffprobeDurationSeconds($ffprobe, $primaryLocal);
            if ($probedS <= 0) {
                $this->fail($row, 'ffprobe_duration', 'Could not read source video duration (ffprobe).', [
                    'ffprobe_binary' => $ffprobe,
                ], $workspace);

                return;
            }

            $timing = StudioCompositionVideoExportMediaHelper::computeTrimAndOutputDuration($doc, $videoLayer, $probedS);
            $hasAudio = StudioCompositionVideoExportMediaHelper::ffprobeHasAudio($ffprobe, $primaryLocal);
            $tl = is_array($videoLayer['timeline'] ?? null) ? $videoLayer['timeline'] : [];
            $tlMuted = (bool) ($tl['muted'] ?? false);

            $timeline = $this->normalizer->buildTimeline($composition, $videoLayer, $probedS);
            $overlays = $this->normalizer->buildOverlayLayers($composition, $videoLayer, $timeline);

            $stagedLayers = [];
            foreach ($overlays as $ly) {
                if ($ly->type === 'text') {
                    try {
                        $png = $this->textRasterizer->rasterizeToPath(
                            $ly,
                            $workspace,
                            $tenant,
                            $composition->brand_id !== null ? (int) $composition->brand_id : null,
                        );
                    } catch (StudioFontResolutionException $e) {
                        $this->fail($row, 'studio_font:'.$e->errorCode, $e->getMessage(), array_merge($e->context, [
                            'layer_id' => $ly->id,
                            'exception_class' => $e::class,
                        ]), $workspace);

                        return;
                    } catch (\Throwable $e) {
                        $this->fail($row, 'text_rasterization_failed', $e->getMessage(), [
                            'layer_id' => $ly->id,
                            'exception_class' => $e::class,
                        ], $workspace);

                        return;
                    }
                    $stagedLayers[] = new RenderLayer(
                        id: $ly->id,
                        type: 'image',
                        zIndex: $ly->zIndex,
                        startSeconds: $ly->startSeconds,
                        endSeconds: $ly->endSeconds,
                        visible: $ly->visible,
                        x: $ly->x,
                        y: $ly->y,
                        width: $ly->width,
                        height: $ly->height,
                        opacity: $ly->opacity,
                        rotationDegrees: $ly->rotationDegrees,
                        fit: 'fill',
                        isPrimaryVideo: false,
                        mediaPath: $png,
                        trimInMs: $ly->trimInMs,
                        trimOutMs: $ly->trimOutMs,
                        muted: $ly->muted,
                        fadeInMs: $ly->fadeInMs,
                        fadeOutMs: $ly->fadeOutMs,
                        extra: ['from_text' => true],
                    );

                    continue;
                }
                $stagedLayers[] = $ly;
            }

            try {
                $stagedLayers = $this->stager->stageOverlayLayers($tenant, $stagedLayers, $workspace);
            } catch (\Throwable $e) {
                $this->fail($row, 'asset_stage_failed', $e->getMessage(), [], $workspace);

                return;
            }

            $stagedMeta = [
                'primary_video' => $primaryLocal,
                'trim_in_s' => $timing['trim_in_s'],
                'trim_out_s' => $timing['trim_out_s'],
                'base_has_audio' => $hasAudio,
                'layer_muted' => $tlMuted,
            ];

            $request = new CompositionRenderRequest(
                exportJob: $row,
                tenant: $tenant,
                user: $user,
                workspacePath: $workspace,
                timeline: $timeline,
                layers: $stagedLayers,
                includeAudio: $includeAudio,
                stagedPathsByKey: $stagedMeta,
            );

            $valErrors = $this->validator->validate($request);
            if ($valErrors !== []) {
                $this->fail($row, 'render_validation_failed', implode('; ', $valErrors), [
                    'errors' => $valErrors,
                ], $workspace);

                return;
            }

            $rendered = $this->renderer->render($request);
            if (! $rendered->ok || $rendered->localMp4Path === null) {
                $this->fail($row, (string) ($rendered->failureCode ?? 'ffmpeg_render_failed'), (string) ($rendered->failureMessage ?? 'Render failed.'), array_merge(
                    is_array($rendered->diagnostics) ? $rendered->diagnostics : [],
                    ['normalized_summary' => [
                        'canvas_w' => $timeline->width,
                        'canvas_h' => $timeline->height,
                        'fps' => $timeline->fps,
                        'duration_ms' => $timeline->durationMs,
                        'overlay_count' => count($stagedLayers),
                    ]],
                ), $workspace);

                return;
            }

            $technical = array_merge([
                'implementation' => 'ffmpeg_native_filtergraph_v1',
                'render_mode' => StudioCompositionVideoExportRenderMode::FFMPEG_NATIVE->value,
                'include_audio' => $includeAudio,
                'audio_muxed' => $includeAudio && $hasAudio && ! $tlMuted,
                'ffmpeg_diagnostics' => $rendered->diagnostics ?? [],
            ], is_array($rendered->diagnostics) ? $rendered->diagnostics : []);

            $published = $this->mp4Publisher->publish(
                $row,
                $composition,
                $tenant,
                $user,
                $rendered->localMp4Path,
                $timeline->width,
                $timeline->height,
                $technical,
            );
            $assetOut = $published['asset'];
            $technical = $published['technical'];

            $row->update([
                'status' => StudioCompositionVideoExportJob::STATUS_COMPLETE,
                'meta_json' => array_merge($preserved, $technical, [
                    'ffmpeg_native_export' => [
                        'workspace_path' => $workspace,
                        'completed_at' => now()->toIso8601String(),
                    ],
                ]),
                'error_json' => null,
                'output_asset_id' => $assetOut->id,
            ]);

            Log::info('[StudioCompositionFfmpegNativeVideoExportService] complete', [
                'export_job_id' => $row->id,
                'output_asset_id' => $assetOut->id,
            ]);
        } catch (\Throwable $e) {
            $this->fail($row, 'ffmpeg_native_exception', $e->getMessage(), [
                'exception_class' => $e::class,
            ], is_string($workspace) ? $workspace : null);

            return;
        } finally {
            if (is_string($workspace)) {
                RenderWorkspace::purge($workspace);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $debug
     */
    /**
     * @param  array<int, mixed>  $layers
     */
    private static function documentHasVisibleGradientFill(array $layers): bool
    {
        foreach ($layers as $ly) {
            if (! is_array($ly)) {
                continue;
            }
            if (($ly['visible'] ?? true) === false) {
                continue;
            }
            if (($ly['type'] ?? '') !== 'fill') {
                continue;
            }
            if ((string) ($ly['fillKind'] ?? 'solid') === 'gradient') {
                return true;
            }
        }

        return false;
    }

    private function fail(
        StudioCompositionVideoExportJob $row,
        string $code,
        string $message,
        array $debug,
        ?string $workspace = null,
    ): void {
        if ($workspace !== null) {
            RenderWorkspace::purge($workspace);
        }
        $row->update([
            'status' => StudioCompositionVideoExportJob::STATUS_FAILED,
            'error_json' => [
                'code' => $code,
                'message' => $message,
                'debug' => array_merge($debug, [
                    'workspace_path' => $workspace,
                ]),
            ],
        ]);
    }
}
