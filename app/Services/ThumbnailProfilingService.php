<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetVersion;
use App\Support\EditorAssetOriginalBytesLoader;
use App\Support\ThumbnailMode;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Local diagnostics: times the same download + raster path as {@see ThumbnailGenerationService::generateThumbnails}
 * without persisting thumbnails or metadata unless explicitly requested by the caller.
 */
final class ThumbnailProfilingService
{
    public function __construct(
        private ThumbnailGenerationService $thumbnails,
    ) {}

    /**
     * @return array{
     *   lookup_ms: float,
     *   head_ms: float,
     *   read_ms: float,
     *   detect_ms: float,
     *   source_probe_ms: float,
     *   preferred_crop_ms: float,
     *   pipeline_ms: float,
     *   file_meta_ms: float,
     *   decode_ms: float,
     *   normalize_ms: float,
     *   resize_ms: float,
     *   encode_ms: float,
     *   write_ms: float,
     *   db_ms: float,
     *   total_ms: float,
     *   output_bytes: int,
     *   output_path: string|null,
     *   mime_type: string|null,
     *   original_bytes: int,
     *   original_dimensions: string|null,
     *   disk: string,
     *   source_path: string,
     *   file_type: string,
     *   inner_timings: ?array<string, float>
     * }
     */
    public function profileOnce(
        Asset $asset,
        ?AssetVersion $version,
        string $mode,
        string $styleName,
        bool $writeDebugFile,
    ): array {
        $mode = ThumbnailMode::normalize($mode);
        if (! in_array($mode, [ThumbnailMode::Original->value, ThumbnailMode::Preferred->value], true)) {
            throw new \InvalidArgumentException(
                'This profiler supports modes "original" and "preferred" only (library raster pipeline). '.
                'Enhanced/presentation use separate composition paths.'
            );
        }

        $tTotal = microtime(true);
        $lookupMs = 0.0;
        $headMs = 0.0;
        $readMs = 0.0;
        $detectMs = 0.0;
        $sourceProbeMs = 0.0;
        $preferredCropMs = 0.0;
        $pipelineMs = 0.0;
        $writeMs = 0.0;
        $dbMs = 0.0;

        $fileMetaMs = 0.0;
        $decodeMs = 0.0;
        $normalizeMs = 0.0;
        $resizeMs = 0.0;
        $encodeMs = 0.0;
        $innerTimings = null;

        $tempFiles = [];

        try {
            $t0 = microtime(true);
            $asset->loadMissing('storageBucket', 'tenant');
            $lookupMs = (microtime(true) - $t0) * 1000;

            $sourceS3Path = ($version !== null && is_string($version->file_path) && $version->file_path !== '')
                ? $version->file_path
                : (string) $asset->storage_root_path;
            if ($sourceS3Path === '') {
                throw new \RuntimeException('Asset has no storage path (version file_path / storage_root_path).');
            }

            $bucket = $asset->storageBucket;
            $diskLabel = $bucket !== null
                ? 's3:'.$bucket->name
                : (EditorAssetOriginalBytesLoader::resolveFallbackDiskForObjectKey($asset, $sourceS3Path) ?? 'local:fallback');

            if ($bucket !== null) {
                $tHead = microtime(true);
                $this->thumbnails->headObjectFingerprint($bucket, $sourceS3Path);
                $headMs = (microtime(true) - $tHead) * 1000;
            }

            $tRead = microtime(true);
            $localPath = $this->thumbnails->downloadOriginalToTempForDiagnostics($asset, $sourceS3Path);
            $tempFiles[] = $localPath;
            $readMs = (microtime(true) - $tRead) * 1000;

            if (! is_file($localPath) || filesize($localPath) === 0) {
                throw new \RuntimeException('Downloaded source file is missing or empty.');
            }
            $originalBytes = (int) filesize($localPath);

            $this->thumbnails->resetDiagnosticsGenerationState($mode);

            $tDet = microtime(true);
            $fileType = $this->thumbnails->detectFileTypeForDiagnostics($asset, $version);
            $detectMs = (microtime(true) - $tDet) * 1000;

            $tProbe = microtime(true);
            $originalDimensions = $this->probeSourceDimensions($fileType, $localPath);
            $sourceProbeMs = (microtime(true) - $tProbe) * 1000;

            if ($mode === ThumbnailMode::Preferred->value
                && in_array($fileType, ['image', 'tiff', 'avif', 'cr2'], true)
            ) {
                $tCrop = microtime(true);
                $crop = $this->thumbnails->applyPreferredCropForDiagnostics($localPath);
                $preferredCropMs = (microtime(true) - $tCrop) * 1000;

                if (($crop['applied'] ?? false)
                    && ($crop['path'] ?? '') !== ''
                    && $crop['path'] !== $localPath
                    && is_file($crop['path'])) {
                    $tempFiles[] = $crop['path'];
                    @unlink($localPath);
                    $tempFiles = array_values(array_filter($tempFiles, fn (string $p) => $p !== $localPath));
                    $localPath = $crop['path'];
                    $originalBytes = (int) filesize($localPath);
                    if ($fileType === 'image') {
                        $info = @getimagesize($localPath);
                        if ($info !== false) {
                            $originalDimensions = (int) $info[0].'×'.(int) $info[1];
                        }
                    }
                }
            }

            $mimeType = $version?->mime_type ?? $asset->mime_type;

            $this->thumbnails->beginDiagnosticThumbnailTimings();
            $tPipe = microtime(true);
            try {
                $outPath = $this->thumbnails->generateOneThumbnailStyleForDiagnostics($asset, $localPath, $styleName, $fileType);
            } finally {
                $innerTimings = $this->thumbnails->takeDiagnosticThumbnailTimings();
            }
            $pipelineMs = (microtime(true) - $tPipe) * 1000;

            $tempFiles[] = $outPath;

            $outputBytes = (int) filesize($outPath);

            if (is_array($innerTimings)) {
                $fileMetaMs = (float) ($innerTimings['file_meta_ms'] ?? 0);
                $decodeMs = (float) ($innerTimings['decode_ms'] ?? 0);
                $normalizeMs = (float) ($innerTimings['normalize_ms'] ?? 0);
                $resizeMs = (float) ($innerTimings['resize_ms'] ?? 0);
                $encodeMs = (float) ($innerTimings['encode_ms'] ?? 0);
            }

            $savedPath = null;
            if ($writeDebugFile) {
                $dir = storage_path('app/debug/thumbnail-profiles/'.$asset->id);
                File::ensureDirectoryExists($dir);
                $ts = now()->format('YmdHisu');
                $ext = pathinfo($outPath, PATHINFO_EXTENSION) ?: 'jpg';
                $dest = $dir.'/'.$ts.'-'.$styleName.'.'.$ext;
                $tW = microtime(true);
                File::copy($outPath, $dest);
                $writeMs = (microtime(true) - $tW) * 1000;
                $savedPath = $dest;
            }

            $totalMs = (microtime(true) - $tTotal) * 1000;

            return [
                'lookup_ms' => round($lookupMs, 3),
                'head_ms' => round($headMs, 3),
                'read_ms' => round($readMs, 3),
                'detect_ms' => round($detectMs, 3),
                'source_probe_ms' => round($sourceProbeMs, 3),
                'preferred_crop_ms' => round($preferredCropMs, 3),
                'pipeline_ms' => round($pipelineMs, 3),
                'file_meta_ms' => round($fileMetaMs, 3),
                'decode_ms' => round($decodeMs, 3),
                'normalize_ms' => round($normalizeMs, 3),
                'resize_ms' => round($resizeMs, 3),
                'encode_ms' => round($encodeMs, 3),
                'write_ms' => round($writeMs, 3),
                'db_ms' => round($dbMs, 3),
                'total_ms' => round($totalMs, 3),
                'output_bytes' => $outputBytes,
                'output_path' => $savedPath,
                'mime_type' => $mimeType,
                'original_bytes' => $originalBytes,
                'original_dimensions' => $originalDimensions,
                'disk' => $diskLabel,
                'source_path' => $sourceS3Path,
                'file_type' => $fileType,
                'inner_timings' => $innerTimings,
            ];
        } finally {
            foreach (array_unique($tempFiles) as $p) {
                if (is_string($p) && $p !== '' && is_file($p)) {
                    @unlink($p);
                }
            }
        }
    }

    private function probeSourceDimensions(string $fileType, string $localPath): ?string
    {
        if ($fileType === 'image') {
            $info = @getimagesize($localPath);
            if ($info !== false) {
                return (int) $info[0].'×'.(int) $info[1];
            }

            return null;
        }

        if (in_array($fileType, ['tiff', 'avif', 'cr2'], true) && extension_loaded('imagick')) {
            try {
                $im = new \Imagick;
                $spec = $fileType === 'cr2' ? $localPath.'[0]' : $localPath;
                $im->pingImage($spec);
                $im->setIteratorIndex(0);
                $w = (int) $im->getImageWidth();
                $h = (int) $im->getImageHeight();
                $im->clear();
                $im->destroy();
                if ($w > 0 && $h > 0) {
                    return $w.'×'.$h;
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $samples
     * @return array<string, float|int|string|null>
     */
    public static function averageSamples(array $samples): array
    {
        if ($samples === []) {
            return [];
        }
        $n = count($samples);
        $keys = [
            'lookup_ms', 'head_ms', 'read_ms', 'detect_ms', 'source_probe_ms', 'preferred_crop_ms',
            'pipeline_ms', 'file_meta_ms', 'decode_ms', 'normalize_ms', 'resize_ms', 'encode_ms',
            'write_ms', 'db_ms', 'total_ms',
        ];
        $out = [];
        foreach ($keys as $k) {
            $sum = 0.0;
            foreach ($samples as $row) {
                $sum += (float) ($row[$k] ?? 0);
            }
            $out[$k] = round($sum / $n, 3);
        }
        $out['iterations'] = $n;
        $last = $samples[$n - 1];
        $out['output_bytes'] = $last['output_bytes'] ?? null;
        $out['original_bytes'] = $last['original_bytes'] ?? null;
        $out['original_dimensions'] = $last['original_dimensions'] ?? null;
        $out['disk'] = $last['disk'] ?? null;
        $out['source_path'] = $last['source_path'] ?? null;
        $out['mime_type'] = $last['mime_type'] ?? null;

        return $out;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function logStructured(Asset $asset, array $row): void
    {
        Log::info('[thumbnail_profile]', [
            'asset_id' => $asset->id,
            'tenant_id' => $asset->tenant_id,
            'brand_id' => $asset->brand_id,
            'mime_type' => $row['mime_type'] ?? null,
            'original_bytes' => $row['original_bytes'] ?? null,
            'original_dimensions' => $row['original_dimensions'] ?? null,
            'disk' => $row['disk'] ?? null,
            'read_ms' => $row['read_ms'] ?? null,
            'decode_ms' => $row['decode_ms'] ?? null,
            'resize_ms' => $row['resize_ms'] ?? null,
            'encode_ms' => $row['encode_ms'] ?? null,
            'write_ms' => $row['write_ms'] ?? null,
            'db_ms' => $row['db_ms'] ?? null,
            'total_ms' => $row['total_ms'] ?? null,
            'head_ms' => $row['head_ms'] ?? null,
            'file_meta_ms' => $row['file_meta_ms'] ?? null,
            'normalize_ms' => $row['normalize_ms'] ?? null,
            'preferred_crop_ms' => $row['preferred_crop_ms'] ?? null,
            'pipeline_ms' => $row['pipeline_ms'] ?? null,
        ]);
    }
}
