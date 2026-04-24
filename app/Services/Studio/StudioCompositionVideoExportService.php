<?php

namespace App\Services\Studio;

use App\Models\Asset;
use App\Models\Composition;
use App\Models\StudioCompositionVideoExportJob;
use App\Models\Tenant;
use App\Models\User;
use App\Support\EditorAssetOriginalBytesLoader;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Bakes a composition MP4: scale/pad the first video layer to the canvas, then (when present) overlays
 * image / generative_image layers with z-order above the video.
 *
 * Not baked in this pipeline (editor-only today): text layers, mask clipping, blend modes, fill borders,
 * and other non-raster effects. Callers should surface that in UI so “live type” is not expected in the MP4.
 */
final class StudioCompositionVideoExportService
{
    public function __construct(
        protected StudioCompositionVideoExportMp4Publisher $mp4Publisher,
    ) {}

    public function run(StudioCompositionVideoExportJob $row, Tenant $tenant, User $user): void
    {
        if (! (bool) config('studio_video.export_enabled', true)) {
            $row->update([
                'status' => StudioCompositionVideoExportJob::STATUS_FAILED,
                'error_json' => ['message' => 'Video export is disabled.'],
            ]);

            return;
        }

        $row->refresh();
        $requestMeta = is_array($row->meta_json) ? $row->meta_json : [];
        $includeAudioPref = $requestMeta['include_audio'] ?? true;
        $includeAudioPref = is_bool($includeAudioPref) ? $includeAudioPref : (bool) $includeAudioPref;

        $row->update(['status' => StudioCompositionVideoExportJob::STATUS_PROCESSING]);

        $composition = Composition::query()
            ->where('id', $row->composition_id)
            ->where('tenant_id', $tenant->id)
            ->first();
        if (! $composition) {
            $row->update([
                'status' => StudioCompositionVideoExportJob::STATUS_FAILED,
                'error_json' => ['message' => 'Composition not found.'],
            ]);

            return;
        }

        $doc = is_array($composition->document_json) ? $composition->document_json : [];
        $w = (int) ($doc['width'] ?? 0);
        $h = (int) ($doc['height'] ?? 0);
        $layers = is_array($doc['layers'] ?? null) ? $doc['layers'] : [];
        $videoLayer = StudioCompositionVideoExportMediaHelper::selectPrimaryVideoLayer($layers);
        if ($videoLayer === null || $w < 2 || $h < 2) {
            $row->update([
                'status' => StudioCompositionVideoExportJob::STATUS_FAILED,
                'error_json' => ['message' => 'Composition needs a valid canvas and at least one video layer.'],
            ]);

            return;
        }

        $videoZ = (int) ($videoLayer['z'] ?? 0);
        $overlayLayerCandidates = [];
        foreach ($layers as $ly) {
            if (! is_array($ly)) {
                continue;
            }
            if (($ly['visible'] ?? true) === false) {
                continue;
            }
            $t = (string) ($ly['type'] ?? '');
            if ($t !== 'image' && $t !== 'generative_image') {
                continue;
            }
            if ((int) ($ly['z'] ?? 0) <= $videoZ) {
                continue;
            }
            $overlayLayerCandidates[] = $ly;
        }
        usort($overlayLayerCandidates, static function (array $a, array $b): int {
            return ((int) ($a['z'] ?? 0)) <=> ((int) ($b['z'] ?? 0));
        });

        $assetId = (string) $videoLayer['assetId'];
        $asset = Asset::query()
            ->where('id', $assetId)
            ->where('tenant_id', $tenant->id)
            ->first();
        if (! $asset) {
            $row->update([
                'status' => StudioCompositionVideoExportJob::STATUS_FAILED,
                'error_json' => ['message' => 'Video asset not found.'],
            ]);

            return;
        }

        $ver = $asset->currentVersion()->first();
        $rel = $ver?->file_path ?? $asset->file_path;
        if (! is_string($rel) || $rel === '') {
            $row->update([
                'status' => StudioCompositionVideoExportJob::STATUS_FAILED,
                'error_json' => ['message' => 'Video asset has no file path.'],
            ]);

            return;
        }

        $tmpIn = storage_path('app/tmp/'.Str::random(20).'_src.mp4');
        $tmpOut = storage_path('app/tmp/'.Str::random(20).'_out.mp4');
        $tmpOverlays = [];
        @mkdir(dirname($tmpIn), 0775, true);

        try {
            try {
                $raw = EditorAssetOriginalBytesLoader::loadFromStorage($asset, $rel);
            } catch (\InvalidArgumentException $e) {
                throw new \RuntimeException('Could not read source video from storage.', 0, $e);
            }
            file_put_contents($tmpIn, $raw);

            $stacks = [];
            foreach ($overlayLayerCandidates as $cand) {
                $tmpPath = $this->tryWriteRasterLayerToTemp($cand, $tenant);
                if (! is_string($tmpPath) || $tmpPath === '') {
                    continue;
                }
                $stacks[] = [
                    'layer' => $cand,
                    'path' => $tmpPath,
                ];
                $tmpOverlays[] = $tmpPath;
            }
            $overlaysAttempted = count($overlayLayerCandidates);
            $overlaysApplied = count($stacks);
            $overlaysSkipped = $overlaysAttempted - $overlaysApplied;

            $ffmpeg = (string) config('studio_video.ffmpeg_binary', 'ffmpeg');
            $ffprobe = (string) config('studio_video.ffprobe_binary', 'ffprobe');
            $probedS = StudioCompositionVideoExportMediaHelper::ffprobeDurationSeconds($ffprobe, $tmpIn);
            if ($probedS <= 0) {
                throw new \RuntimeException('Could not read source video duration (ffprobe).');
            }
            $tl = is_array($videoLayer['timeline'] ?? null) ? $videoLayer['timeline'] : [];
            $timing = StudioCompositionVideoExportMediaHelper::computeTrimAndOutputDuration($doc, $videoLayer, $probedS);
            $trimInMs = $timing['trim_in_ms'];
            $trimOutMs = $timing['trim_out_ms'];
            $trimInS = $timing['trim_in_s'];
            $outDurS = $timing['output_duration_s'];
            $compMs = $timing['composition_duration_ms'];
            $hasAudio = StudioCompositionVideoExportMediaHelper::ffprobeHasAudio($ffprobe, $tmpIn);
            $tlMuted = (bool) ($tl['muted'] ?? false);
            $wantAudio = $includeAudioPref && ! $tlMuted && $hasAudio;
            $padColor = StudioCompositionVideoExportMediaHelper::resolvePadColorForFfmpeg($layers, $videoZ);
            if ($stacks === []) {
                $fc = "[0:v]scale={$w}:{$h}:force_original_aspect_ratio=decrease,pad={$w}:{$h}:(ow-iw)/2:(oh-ih)/2:{$padColor},format=yuv420p,setsar=1[vout]";
                $mapLabel = 'vout';
            } else {
                $filterParts = [];
                $baseLabel = 'bg0';
                $filterParts[] = "[0:v]scale={$w}:{$h}:force_original_aspect_ratio=decrease,pad={$w}:{$h}:(ow-iw)/2:(oh-ih)/2:{$padColor},format=yuv420p,setsar=1[{$baseLabel}]";
                $graphCurrent = $baseLabel;
                $inIdx = 1;
                $oi = 0;
                foreach ($stacks as $item) {
                    $ly = $item['layer'];
                    $t = is_array($ly['transform'] ?? null) ? $ly['transform'] : [];
                    $x = (int) round((float) ($t['x'] ?? 0));
                    $y = (int) round((float) ($t['y'] ?? 0));
                    $tw = max(1, (int) round((float) ($t['width'] ?? 1)));
                    $th = max(1, (int) round((float) ($t['height'] ?? 1)));
                    $fit = (string) ($ly['fit'] ?? 'cover');
                    if (! in_array($fit, ['fill', 'contain', 'cover'], true)) {
                        $fit = 'cover';
                    }
                    if ($fit === 'fill') {
                        $fitExpr = "scale={$tw}:{$th},format=rgba";
                    } elseif ($fit === 'contain') {
                        // Transparent letterbox inside the overlay tile so letterboxing is not painted as opaque black.
                        $fitExpr = "scale={$tw}:{$th}:force_original_aspect_ratio=decrease,pad={$tw}:{$th}:(ow-iw)/2:(oh-ih)/2:color=black@0,format=rgba";
                    } else {
                        $fitExpr = "scale={$tw}:{$th}:force_original_aspect_ratio=increase,crop={$tw}:{$th},format=rgba";
                    }
                    $ovLabel = 'ov'.$oi;
                    $nextLabel = 'st'.$oi;
                    $filterParts[] = "[{$inIdx}:v]{$fitExpr}[{$ovLabel}]";
                    $filterParts[] = "[{$graphCurrent}][{$ovLabel}]overlay={$x}:{$y}:shortest=1:format=auto[{$nextLabel}]";
                    $graphCurrent = $nextLabel;
                    $inIdx++;
                    $oi++;
                }
                $fc = implode(';', $filterParts);
                $mapLabel = $graphCurrent;
            }
            $ffmpegInPrefix = array_merge(
                [$ffmpeg, '-y'],
                $trimInS > 0.0001 ? ['-ss', sprintf('%.5f', $trimInS)] : [],
                ['-i', $tmpIn, '-t', sprintf('%.5f', $outDurS)],
            );
            $argv = $ffmpegInPrefix;
            foreach ($stacks as $item) {
                $argv = array_merge($argv, ['-loop', '1', '-i', $item['path']]);
            }
            $argv = array_merge(
                $argv,
                [
                    '-filter_complex',
                    $fc,
                    '-map',
                    '['.$mapLabel.']',
                ]
            );
            if ($wantAudio) {
                $argv = array_merge(
                    $argv,
                    [
                        '-map', '0:a:0?', '-c:a', 'aac', '-b:a', '192k',
                    ]
                );
            } else {
                $argv[] = '-an';
            }
            $argv = array_merge(
                $argv,
                [
                    '-c:v', 'libx264',
                    '-pix_fmt', 'yuv420p',
                    '-movflags', '+faststart',
                    $tmpOut,
                ]
            );
            $process = new Process($argv);
            $process->setTimeout(3600);
            $process->run();
            if (! $process->isSuccessful() || ! is_file($tmpOut)) {
                throw new \RuntimeException('FFmpeg failed: '.substr($process->getErrorOutput() ?: $process->getOutput(), 0, 2000));
            }

            $technical = [
                'include_audio' => $includeAudioPref,
                'audio_muxed' => $wantAudio,
                'layer_timeline_muted' => $tlMuted,
                'ffmpeg_pad_color' => $padColor,
                'ffmpeg_command_summary' => $stacks === [] ? 'scale+pad base video to canvas' : 'scale+pad base + image overlays (z above video layer)',
                'primary_video_layer_id' => (string) ($videoLayer['id'] ?? ''),
                'probed_source_duration_s' => $probedS,
                'trim_in_ms' => $trimInMs,
                'trim_out_ms' => $trimOutMs,
                'output_duration_cap_s' => $outDurS,
                'composition_duration_ms' => $compMs > 0 ? $compMs : null,
                'overlays_applied' => $overlaysApplied,
                'overlays_skipped' => $overlaysSkipped,
                'overlays_total_candidates' => $overlaysAttempted,
                'text_layers_note' => 'Text layers, masks, blend modes, and non-raster fills are not baked; only image/generative_image overlays.',
            ];
            $published = $this->mp4Publisher->publish($row, $composition, $tenant, $user, $tmpOut, $w, $h, $technical);
            $asset = $published['asset'];
            $technical = $published['technical'];
            $preserved = is_array($row->meta_json) ? $row->meta_json : [];
            $row->update([
                'status' => StudioCompositionVideoExportJob::STATUS_COMPLETE,
                'meta_json' => array_merge($preserved, $technical),
                'error_json' => null,
                'output_asset_id' => $asset->id,
            ]);

            Log::info('[StudioCompositionVideoExportService] complete', [
                'job_row_id' => $row->id,
                'composition_id' => $composition->id,
                'output_asset_id' => $asset->id,
            ]);
        } catch (\Throwable $e) {
            $row->update([
                'status' => StudioCompositionVideoExportJob::STATUS_FAILED,
                'error_json' => ['message' => $e->getMessage()],
            ]);
        } finally {
            @unlink($tmpIn);
            @unlink($tmpOut);
            foreach ($tmpOverlays as $o) {
                if (is_string($o) && $o !== '') {
                    @unlink($o);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $layer
     */
    private function tryWriteRasterLayerToTemp(array $layer, Tenant $tenant): ?string
    {
        $type = (string) ($layer['type'] ?? '');
        $assetId = null;
        if ($type === 'image') {
            $assetId = $layer['assetId'] ?? null;
        } elseif ($type === 'generative_image') {
            $assetId = $layer['resultAssetId'] ?? null;
        }
        if (! is_string($assetId) || $assetId === '') {
            return null;
        }
        $asset = Asset::query()
            ->where('id', $assetId)
            ->where('tenant_id', $tenant->id)
            ->first();
        if (! $asset) {
            return null;
        }
        $ver = $asset->currentVersion()->first();
        $rel = $ver?->file_path ?? $asset->file_path;
        if (! is_string($rel) || $rel === '') {
            return null;
        }
        try {
            $raw = EditorAssetOriginalBytesLoader::loadFromStorage($asset, $rel);
        } catch (\InvalidArgumentException) {
            return null;
        }
        $mime = (string) ($asset->mime_type ?? '');
        $ext = 'png';
        if (str_contains($mime, 'jpeg') || str_contains($mime, 'jpg') || str_ends_with(strtolower($rel), 'jpg') || str_ends_with(strtolower($rel), 'jpeg')) {
            $ext = 'jpg';
        } elseif (str_contains($mime, 'webp') || str_ends_with(strtolower($rel), 'webp')) {
            $ext = 'webp';
        } elseif (str_contains($mime, 'png') || str_ends_with(strtolower($rel), 'png')) {
            $ext = 'png';
        }
        $path = storage_path('app/tmp/'.Str::random(20).'_ol.'.$ext);
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, $raw);

        return $path;
    }
}
