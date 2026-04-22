<?php

namespace App\Services\Studio;

use App\Enums\ApprovalStatus;
use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\Composition;
use App\Models\StudioCompositionVideoExportJob;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AssetPathGenerator;
use App\Support\EditorAssetOriginalBytesLoader;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Bakes a composition MP4: scale/pad the first video layer to the canvas, then (when present) overlays
 * image / generative_image layers with z-order above the video. Text and mask effects are not rendered here
 * (they remain editor-only in this pipeline).
 */
final class StudioCompositionVideoExportService
{
    public function __construct(
        protected AssetPathGenerator $pathGenerator,
        protected EditorStudioVideoPublishApplier $videoPublishApplier,
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
        $videoLayer = $this->selectPrimaryVideoLayer($layers);
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
            $probedS = $this->ffprobeDurationSeconds($ffprobe, $tmpIn);
            if ($probedS <= 0) {
                throw new \RuntimeException('Could not read source video duration (ffprobe).');
            }
            $tl = is_array($videoLayer['timeline'] ?? null) ? $videoLayer['timeline'] : [];
            $trimInMs = max(0, (int) ($tl['trim_in_ms'] ?? 0));
            $trimOutMs = max(0, (int) ($tl['trim_out_ms'] ?? 0));
            $trimInS = $trimInMs / 1000.0;
            $trimOutS = $trimOutMs / 1000.0;
            $availableS = max(0.04, $probedS - $trimInS - $trimOutS);
            $compMs = 0;
            $stDoc = is_array($doc['studio_timeline'] ?? null) ? $doc['studio_timeline'] : null;
            if (is_array($stDoc) && isset($stDoc['duration_ms'])) {
                $compMs = max(0, (int) $stDoc['duration_ms']);
            }
            $compS = $compMs > 0 ? $compMs / 1000.0 : $availableS;
            $outDurS = min($availableS, $compS);
            $hasAudio = $this->ffprobeHasAudio($ffprobe, $tmpIn);
            $tlMuted = (bool) ($tl['muted'] ?? false);
            $wantAudio = $includeAudioPref && ! $tlMuted && $hasAudio;
            if ($stacks === []) {
                $fc = "[0:v]scale={$w}:{$h}:force_original_aspect_ratio=decrease,pad={$w}:{$h}:(ow-iw)/2:(oh-ih)/2,format=yuv420p,setsar=1[vout]";
                $mapLabel = 'vout';
            } else {
                $filterParts = [];
                $baseLabel = 'bg0';
                $filterParts[] = "[0:v]scale={$w}:{$h}:force_original_aspect_ratio=decrease,pad={$w}:{$h}:(ow-iw)/2:(oh-ih)/2,format=yuv420p,setsar=1[{$baseLabel}]";
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
                        $fitExpr = "scale={$tw}:{$th}:force_original_aspect_ratio=decrease,pad={$tw}:{$th}:(ow-iw)/2:(oh-ih)/2,format=rgba";
                    } else {
                        $fitExpr = "scale={$tw}:{$th}:force_original_aspect_ratio=increase,crop={$tw}:{$th},format=rgba";
                    }
                    $ovLabel = 'ov'.$oi;
                    $nextLabel = 'st'.$oi;
                    $filterParts[] = "[{$inIdx}:v]{$fitExpr}[{$ovLabel}]";
                    $filterParts[] = "[{$graphCurrent}][{$ovLabel}]overlay={$x}:{$y}:shortest=1[{$nextLabel}]";
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
                        '-map', '0:a:0', '-c:a', 'aac', '-b:a', '192k',
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

            $bytes = @file_get_contents($tmpOut);
            if ($bytes === false || $bytes === '') {
                throw new \RuntimeException('Export file was empty.');
            }
            $size = strlen($bytes);
            $brand = $composition->brand;
            if (! $brand) {
                throw new \RuntimeException('Composition brand missing.');
            }
            $outDisk = (string) config('studio_animation.output_disk', 's3');
            $newAssetId = (string) Str::uuid();
            $path = $this->pathGenerator->generateOriginalPathForAssetId($tenant, $newAssetId, 1, 'mp4');
            Storage::disk($outDisk)->put($path, $bytes, 'private');

            $asset = Asset::forceCreate([
                'id' => $newAssetId,
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
                'user_id' => $user->id,
                'status' => AssetStatus::VISIBLE,
                'type' => AssetType::AI_GENERATED,
                'title' => 'Studio video export',
                'original_filename' => 'studio-export-'.$row->id.'.mp4',
                'mime_type' => 'video/mp4',
                'size_bytes' => $size,
                'width' => $w,
                'height' => $h,
                'storage_root_path' => $path,
                'thumbnail_status' => ThumbnailStatus::PENDING,
                'analysis_status' => 'uploading',
                'approval_status' => ApprovalStatus::NOT_REQUIRED,
                'published_at' => null,
                'source' => 'studio_composition_video_export',
                'metadata' => [
                    'studio_composition_video_export_job_id' => (string) $row->id,
                    'composition_id' => (string) $composition->id,
                ],
            ]);

            $pub = is_array($requestMeta['editor_publish'] ?? null) ? $requestMeta['editor_publish'] : null;
            if (is_array($pub) && $pub !== [] && $brand) {
                try {
                    $this->videoPublishApplier->apply($asset, $user, $tenant, $brand, $pub);
                } catch (\Throwable $e) {
                    Log::warning('[StudioCompositionVideoExportService] editor_publish apply failed', [
                        'job_id' => $row->id,
                        'asset_id' => $asset->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            AssetVersion::query()->create([
                'id' => (string) Str::uuid(),
                'asset_id' => $asset->id,
                'version_number' => 1,
                'file_path' => $path,
                'file_size' => $size,
                'mime_type' => 'video/mp4',
                'width' => $w,
                'height' => $h,
                'checksum' => hash('sha256', $bytes),
                'is_current' => true,
                'pipeline_status' => 'complete',
                'uploaded_by' => $user->id,
            ]);

            $technical = [
                'include_audio' => $includeAudioPref,
                'audio_muxed' => $wantAudio,
                'layer_timeline_muted' => $tlMuted,
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
                'text_layers_note' => 'Text and mask effects are not baked in this service.',
                'output_asset_id' => $asset->id,
            ];
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
     * Picks the base video for export: primaryForExport (visible) first, else lowest-z visible
     * video, else lowest-z of any video with an asset.
     *
     * @param  array<int, mixed>  $layers
     * @return array<string, mixed>|null
     */
    private function selectPrimaryVideoLayer(array $layers): ?array
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

    private function ffprobeDurationSeconds(string $ffprobe, string $path): float
    {
        if (! is_file($path)) {
            return 0.0;
        }
        $p = new Process([$ffprobe, '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1', $path]);
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

    private function ffprobeHasAudio(string $ffprobe, string $path): bool
    {
        if (! is_file($path)) {
            return false;
        }
        $p = new Process([$ffprobe, '-v', 'error', '-select_streams', 'a:0', '-show_entries', 'stream=index', '-of', 'csv=p=0', $path]);
        $p->setTimeout(60);
        $p->run();

        return $p->isSuccessful() && trim($p->getOutput() ?: '') !== '';
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
