<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Models\AssetPdfPage;
use App\Models\AssetVersion;
use App\Services\PdfPageRenderingService;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class FullPdfExtractionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(
        public readonly string $assetId,
        public readonly ?string $assetVersionId = null
    ) {
        $this->onQueue(config('queue.pdf_processing_queue', 'pdf-processing'));
    }

    public function handle(PdfPageRenderingService $pdfPageRenderingService): void
    {
        $version = $this->assetVersionId
            ? AssetVersion::with(['asset.storageBucket', 'asset.tenant', 'asset.currentVersion'])->find($this->assetVersionId)
            : null;
        $asset = $version?->asset ?: Asset::with(['storageBucket', 'tenant', 'currentVersion'])->findOrFail($this->assetId);

        $mime = strtolower((string) $asset->mime_type);
        if (!str_contains($mime, 'pdf')) {
            return;
        }

        $activeVersion = $version ?: $asset->currentVersion;
        $versionNumber = $activeVersion?->version_number ?? 1;

        $tempPdfPath = null;
        try {
            $pageCount = (int) ($asset->pdf_page_count ?? 0);
            if ($pageCount < 1) {
                $tempPdfPath = $pdfPageRenderingService->downloadSourcePdfToTemp($asset, $activeVersion);
                $pageCount = $pdfPageRenderingService->detectPageCount($tempPdfPath);
            }

            $asset->forceFill([
                'pdf_page_count' => $pageCount,
                'pdf_pages_rendered' => false,
            ])->save();

            if ($pageCount < 1) {
                return;
            }

            $autoExtractLimit = (int) config('assets.thumbnail.pdf.auto_extract_max_pages', 150);
            $targetPageCount = min($pageCount, max(1, $autoExtractLimit));

            $jobs = [];
            for ($page = 1; $page <= $targetPageCount; $page++) {
                $jobs[] = new PdfPageRenderJob($asset->id, $page, $activeVersion?->id);
            }

            if (empty($jobs)) {
                return;
            }

            $assetId = $asset->id;
            $batch = Bus::batch($jobs)
                ->name("pdf-full-extraction:{$assetId}")
                ->allowFailures()
                ->onQueue(config('queue.pdf_processing_queue', 'pdf-processing'))
                ->then(function (Batch $batch) use ($assetId, $versionNumber, $pageCount, $targetPageCount): void {
                    $asset = Asset::find($assetId);
                    if (!$asset) {
                        return;
                    }

                    $completedCount = AssetPdfPage::query()
                        ->where('asset_id', $assetId)
                        ->where('version_number', $versionNumber)
                        ->where('status', 'completed')
                        ->count();

                    $fullyRendered = $completedCount >= $pageCount && $targetPageCount >= $pageCount;
                    $metadata = $asset->metadata ?? [];
                    $metadata['pdf_full_extraction_completed_at'] = now()->toIso8601String();
                    $metadata['pdf_full_extraction_batch_id'] = $batch->id;
                    $metadata['pdf_full_extraction_rendered_pages'] = $completedCount;
                    $metadata['pdf_full_extraction_target_pages'] = $targetPageCount;
                    $metadata['pdf_full_extraction_truncated'] = $targetPageCount < $pageCount;

                    $asset->forceFill([
                        'metadata' => $metadata,
                        'pdf_pages_rendered' => $fullyRendered,
                    ])->save();
                })
                ->catch(function (Batch $batch, \Throwable $e) use ($assetId): void {
                    $asset = Asset::find($assetId);
                    if (!$asset) {
                        return;
                    }

                    $metadata = $asset->metadata ?? [];
                    $metadata['pdf_full_extraction_failed_at'] = now()->toIso8601String();
                    $metadata['pdf_full_extraction_batch_id'] = $batch->id;
                    $metadata['pdf_full_extraction_error'] = $e->getMessage();

                    $asset->forceFill([
                        'metadata' => $metadata,
                        'pdf_pages_rendered' => false,
                    ])->save();
                })
                ->dispatch();

            $metadata = $asset->metadata ?? [];
            $metadata['pdf_full_extraction_batch_id'] = $batch->id;
            $metadata['pdf_full_extraction_started_at'] = now()->toIso8601String();
            $metadata['pdf_full_extraction_target_pages'] = $targetPageCount;
            $metadata['pdf_full_extraction_truncated'] = $targetPageCount < $pageCount;
            $asset->forceFill(['metadata' => $metadata])->save();
        } catch (\Throwable $e) {
            Log::error('[FullPdfExtractionJob] Failed to schedule full extraction', [
                'asset_id' => $asset->id,
                'asset_version_id' => $activeVersion?->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            if ($tempPdfPath && file_exists($tempPdfPath)) {
                @unlink($tempPdfPath);
            }
        }
    }
}
