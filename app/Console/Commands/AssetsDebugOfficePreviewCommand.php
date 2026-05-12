<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\GenerateThumbnailsJob;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Services\Office\LibreOfficeDocumentPreviewService;
use App\Services\ThumbnailGenerationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Local Office → PDF → raster diagnostic for one asset version (read-only unless --repair is passed).
 */
final class AssetsDebugOfficePreviewCommand extends Command
{
    protected $signature = 'assets:debug-office-preview
                            {asset_id : Asset UUID}
                            {--keep-temp : Keep the debug run directory under storage/app/tmp/office-debug/}
                            {--style=thumb : Thumbnail style: preview|thumb|medium|large (alias: grid → thumb)}
                            {--timeout=120 : Override assets.thumbnail.office.timeout_seconds for this run}
                            {--version= : Asset version UUID (defaults to current version when set on asset)}
                            {--repair : After diagnostics, run GenerateThumbnailsJob (updates thumbnails + metadata; mutates asset)}';

    protected $description = 'Download version original, run LibreOffice→PDF and PDF→image like thumbnails, emit one local debug raster (read-only unless --repair)';

    public function handle(
        ThumbnailGenerationService $thumbnails,
        LibreOfficeDocumentPreviewService $libreOffice,
    ): int {
        $assetId = (string) $this->argument('asset_id');
        $keepTemp = (bool) $this->option('keep-temp');
        $timeout = max(15, (int) $this->option('timeout'));
        $repair = (bool) $this->option('repair');
        $versionOption = $this->option('version');
        $versionId = is_string($versionOption) && $versionOption !== '' ? $versionOption : null;

        $styleRaw = strtolower(trim((string) $this->option('style')));
        $styleName = match ($styleRaw) {
            'grid' => 'thumb',
            default => $styleRaw,
        };

        $styles = config('assets.thumbnail_styles', []);
        if (! isset($styles[$styleName]) || ! is_array($styles[$styleName])) {
            $this->error('Unknown style "'.$styleName.'". Configured keys: '.implode(', ', array_keys($styles)));

            return self::FAILURE;
        }
        $styleConfig = $styles[$styleName];

        $asset = Asset::query()
            ->with(['currentVersion', 'storageBucket'])
            ->find($assetId);

        if (! $asset) {
            $this->error("Asset not found: {$assetId}");

            return self::FAILURE;
        }

        $version = $versionId !== null
            ? AssetVersion::query()->where('asset_id', $asset->id)->where('id', $versionId)->first()
            : $asset->currentVersion;

        if ($version === null && $versionId !== null) {
            $this->error("Version not found for this asset: {$versionId}");

            return self::FAILURE;
        }

        $sourceS3Path = ($version !== null && is_string($version->file_path) && $version->file_path !== '')
            ? $version->file_path
            : (string) $asset->storage_root_path;

        if ($sourceS3Path === '') {
            $this->error('Asset has no storage path (set version file_path or asset storage_root_path).');

            return self::FAILURE;
        }

        $fileType = $thumbnails->detectFileTypeForDiagnostics($asset, $version);
        if ($fileType !== 'office') {
            $this->error('Detected file type is "'.$fileType.'" (expected office). Use an Office document (pptx, docx, xlsx, etc.).');

            return self::FAILURE;
        }

        $runId = uniqid('run_', true);
        $runDir = storage_path('app/tmp/office-debug/'.$asset->id.'/'.$runId);
        File::ensureDirectoryExists($runDir);

        $prevTimeout = config('assets.thumbnail.office.timeout_seconds');
        config(['assets.thumbnail.office.timeout_seconds' => $timeout]);

        $tmpDownload = null;
        $loDiag = null;
        $rasterPath = null;
        $thumbPath = null;
        $pdfCopy = $runDir.'/intermediate.pdf';
        $pageCopy = $runDir.'/page1_raster';
        $thumbCopy = $runDir.'/debug_'.$styleName;

        try {
            $this->info('Office preview debug');
            $this->line('  asset_id: '.$asset->id);
            $this->line('  version_id: '.($version?->id ?? '(legacy — no version row)'));
            $this->line('  storage path: '.$sourceS3Path);
            $this->line('  debug run dir: '.$runDir);
            $this->line('  mime: '.($version?->mime_type ?? $asset->mime_type));
            $this->line('  style: '.$styleName);
            $this->line('  timeout_seconds (override): '.$timeout);
            $this->newLine();

            $tmpDownload = $thumbnails->downloadOriginalToTempForDiagnostics($asset, $sourceS3Path);
            $sizeBytes = is_file($tmpDownload) ? (int) filesize($tmpDownload) : 0;
            if ($sizeBytes <= 0) {
                $this->error('Downloaded source is missing or empty.');

                return self::FAILURE;
            }

            $ext = strtolower(pathinfo($asset->original_filename ?: $sourceS3Path, PATHINFO_EXTENSION)) ?: 'bin';
            $localSource = $runDir.'/source.'.$ext;
            if (! @copy($tmpDownload, $localSource)) {
                $this->error('Failed to copy downloaded source into debug directory.');

                return self::FAILURE;
            }
            $this->line('  file_size_bytes: '.$sizeBytes);
            $this->line('  local temp source path: '.$localSource);
            $this->newLine();

            $loContext = [
                'asset_id' => $asset->id,
                'asset_version_id' => $version?->id,
                'original_filename' => $asset->original_filename,
                'mime_type' => $version?->mime_type ?? $asset->mime_type,
                'job_temp_dir' => $runDir,
                'command' => 'assets:debug-office-preview',
            ];

            $deleteLoWorkDirOnFail = ! $keepTemp;
            $loDiag = $libreOffice->convertToPdfWithDiagnostics($localSource, $loContext, $deleteLoWorkDirOnFail);

            $imageRenderExists = false;

            $binary = (string) ($loDiag['libreoffice_binary'] ?? '');
            $this->line('LibreOffice binary: '.($binary !== '' ? $binary : '(none)'));
            $this->line('LibreOffice version: '.(($lv = trim((string) ($loDiag['libreoffice_version_line'] ?? ''))) !== '' ? $lv : '(unknown)'));
            $this->line('Command: '.($loDiag['command'] ?? ''));
            $this->line('Exit code: '.(string) ($loDiag['exit_code'] ?? ''));
            $this->line('PDF exists: '.((($loDiag['pdf_exists'] ?? false) === true) ? 'yes' : 'no'));
            $this->line('Work dir: '.(string) ($loDiag['work_dir'] ?? ''));
            $this->line('Output files (work dir): '.json_encode($loDiag['output_dir_files'] ?? []));
            $this->newLine();
            $this->line('--- stdout (LibreOffice merges stderr into stdout when using 2>&1) ---');
            $this->line((string) ($loDiag['stdout'] ?? ''));
            $this->newLine();

            if (($loDiag['success'] ?? false) !== true && ! empty($loDiag['error_message'])) {
                $this->warn('LibreOffice error_message: '.(string) $loDiag['error_message']);
                $this->newLine();
            }

            if (($loDiag['success'] ?? false) === true && is_string($loDiag['pdf_path'] ?? null)) {
                $pdfPath = (string) $loDiag['pdf_path'];
                if (@copy($pdfPath, $pdfCopy)) {
                    $this->line('Copied intermediate PDF to: '.$pdfCopy);
                }
                $workDir = (string) ($loDiag['work_dir'] ?? '');
                if ($workDir !== '' && is_dir($workDir)) {
                    try {
                        File::deleteDirectory($workDir);
                    } catch (\Throwable) {
                    }
                }

                try {
                    $rasterPath = $thumbnails->diagnosticExtractPdfFirstPage($pdfCopy);
                    $rasterExt = strtolower(pathinfo($rasterPath, PATHINFO_EXTENSION)) ?: 'png';
                    $pageDest = $pageCopy.'.'.$rasterExt;
                    if (@copy($rasterPath, $pageDest)) {
                        $this->line('Page-1 raster copy: '.$pageDest);
                    }
                    $styleConfig['_asset_id'] = $asset->id;
                    $styleConfig['_asset_version_id'] = $version?->id;
                    $styleConfig['_original_filename'] = $asset->original_filename;
                    $styleConfig['_mime_type'] = $version?->mime_type ?? $asset->mime_type;

                    $thumbPath = $thumbnails->diagnosticResizePdfRasterToThumbnail($rasterPath, $styleConfig, $pdfCopy);
                    $thumbExt = strtolower(pathinfo($thumbPath, PATHINFO_EXTENSION)) ?: 'webp';
                    $thumbDest = $thumbCopy.'.'.$thumbExt;
                    if (@copy($thumbPath, $thumbDest)) {
                        $this->line('Debug thumbnail: '.$thumbDest);
                        $imageRenderExists = is_file($thumbDest) && filesize($thumbDest) > 0;
                    }
                } catch (\Throwable $e) {
                    $this->warn('PDF raster / resize failed: '.$e->getMessage());
                } finally {
                    if (is_string($rasterPath) && is_file($rasterPath)) {
                        @unlink($rasterPath);
                    }
                    if (is_string($thumbPath) && is_file($thumbPath)) {
                        @unlink($thumbPath);
                    }
                }
            } else {
                $this->warn('Skipping PDF→image: LibreOffice did not produce a PDF.');
            }

            $debugDirFiles = is_dir($runDir)
                ? array_values(array_filter(scandir($runDir) ?: [], static fn (string $f): bool => $f !== '.' && $f !== '..'))
                : [];
            $this->line('Image render exists: '.($imageRenderExists ? 'yes' : 'no'));
            $this->line('Generated files (debug dir): '.json_encode($debugDirFiles));
            $this->newLine();

            $logPayload = [
                'asset_id' => $asset->id,
                'version_id' => $version?->id,
                'storage_path' => $sourceS3Path,
                'local_debug_dir' => $runDir,
                'local_temp_path' => $localSource,
                'mime_type' => $version?->mime_type ?? $asset->mime_type,
                'file_size_bytes' => $sizeBytes,
                'libreoffice_binary' => $loDiag['libreoffice_binary'] ?? null,
                'libreoffice_version_line' => $loDiag['libreoffice_version_line'] ?? null,
                'command' => $loDiag['command'] ?? null,
                'exit_code' => $loDiag['exit_code'] ?? null,
                'stdout' => $loDiag['stdout'] ?? null,
                'stderr' => $loDiag['stderr'] ?? null,
                'output_dir_files' => $loDiag['output_dir_files'] ?? null,
                'pdf_exists' => $loDiag['pdf_exists'] ?? null,
                'pdf_size' => $loDiag['pdf_size'] ?? null,
                'libreoffice_success' => $loDiag['success'] ?? null,
                'libreoffice_error_message' => $loDiag['error_message'] ?? null,
                'libreoffice_work_dir' => $loDiag['work_dir'] ?? null,
                'image_render_exists' => $imageRenderExists,
                'debug_dir_files' => $debugDirFiles,
                'memory_bytes_peak' => memory_get_peak_usage(true),
                'debug_style' => $styleName,
                'repair_dispatched' => false,
            ];

            if ($repair) {
                $jobVersionId = $version?->id ?? $asset->id;
                $this->warn('Running GenerateThumbnailsJob (production pipeline) for version/asset id: '.$jobVersionId);
                (new GenerateThumbnailsJob($jobVersionId, true))->handle();
                $logPayload['repair_dispatched'] = true;
                $this->info('GenerateThumbnailsJob finished.');
            }

            Log::info('[assets:debug-office-preview]', $logPayload);

            if (! $keepTemp) {
                try {
                    File::deleteDirectory($runDir);
                } catch (\Throwable) {
                }
                $this->comment('Removed debug run directory.');
            } else {
                $this->comment('Kept debug run directory: '.$runDir);
            }

            return (($loDiag['success'] ?? false) === true && $imageRenderExists) ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            Log::warning('[assets:debug-office-preview] failed', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return self::FAILURE;
        } finally {
            config(['assets.thumbnail.office.timeout_seconds' => $prevTimeout]);
            if ($tmpDownload !== null && is_file($tmpDownload)) {
                @unlink($tmpDownload);
            }
            if (! $keepTemp && is_dir($runDir)) {
                try {
                    File::deleteDirectory($runDir);
                } catch (\Throwable) {
                }
            }
        }
    }
}
