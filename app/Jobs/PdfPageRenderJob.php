<?php

namespace App\Jobs;

use App\Jobs\Concerns\AppliesQueueSafeModeMiddleware;
use App\Models\Asset;
use App\Models\AssetPdfPage;
use App\Models\AssetVersion;
use App\Services\Assets\AssetProcessingBudgetService;
use App\Services\AssetVariantPathResolver;
use App\Services\PdfPageRenderingService;
use App\Services\TenantBucketService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PdfPageRenderJob implements ShouldQueue
{
    use AppliesQueueSafeModeMiddleware, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 32;

    public int $maxExceptions = 1;

    public int $timeout = 300;

    /** @var array<int,int> */
    public $backoff = [30, 120, 300];

    public function __construct(
        public readonly string $assetId,
        public readonly int $page,
        public readonly ?string $assetVersionId = null
    ) {
        $this->onQueue(config('queue.pdf_processing_queue', 'pdf-processing'));
    }

    public function handle(
        PdfPageRenderingService $pdfPageRenderingService,
        TenantBucketService $tenantBucketService
    ): void {
        $version = $this->assetVersionId
            ? AssetVersion::with(['asset.storageBucket', 'asset.tenant', 'asset.currentVersion'])->find($this->assetVersionId)
            : null;
        $asset = $version?->asset ?: Asset::with(['storageBucket', 'tenant', 'currentVersion'])->findOrFail($this->assetId);

        $mime = strtolower((string) ($asset->mime_type ?? ''));
        $extension = strtolower(pathinfo((string) ($asset->original_filename ?? ''), PATHINFO_EXTENSION));
        $activeVersion = $version ?: $asset->currentVersion;
        $pathExtension = $activeVersion?->file_path
            ? strtolower(pathinfo($activeVersion->file_path, PATHINFO_EXTENSION))
            : '';
        $fileTypeService = app(\App\Services\FileTypeService::class);
        $isNativePdf = str_contains($mime, 'pdf') || $extension === 'pdf' || $pathExtension === 'pdf';
        $isOfficeWithPreview = $fileTypeService->isOfficeDocument($mime, $extension)
            && is_string(data_get($asset->metadata, 'office.preview_pdf_path'))
            && data_get($asset->metadata, 'office.preview_pdf_path') !== '';
        $isPdfLike = $isNativePdf || $isOfficeWithPreview;
        if (! $isPdfLike) {
            return;
        }

        $budgetSvc = app(AssetProcessingBudgetService::class);
        $hints = [
            'file_size_bytes' => max((int) ($activeVersion?->file_size ?? 0), (int) ($asset->size_bytes ?? 0)),
            'mime_type' => $activeVersion?->mime_type ?? $asset->mime_type,
        ];
        $budgetDecision = $budgetSvc->classify($asset, $activeVersion, $hints);
        if (! $budgetDecision->isAllowed()) {
            $budgetSvc->logGuardrail($asset, $activeVersion, $budgetDecision, 'PdfPageRenderJob');
            Log::info('[PdfPageRenderJob] Skipping PDF render — worker processing budget', [
                'asset_id' => $asset->id,
                'decision' => $budgetDecision->kind,
            ]);

            return;
        }

        $versionNumber = $activeVersion?->version_number ?? 1;
        $page = max(1, $this->page);

        $deterministicPath = AssetVariantPathResolver::resolvePdfPagePath($asset, $page, $versionNumber);
        $bucket = $asset->storageBucket;

        if ($bucket && $tenantBucketService->objectExists($bucket, $deterministicPath)) {
            $pageRecord = AssetPdfPage::updateOrCreate(
                [
                    'asset_id' => $asset->id,
                    'version_number' => $versionNumber,
                    'page_number' => $page,
                ],
                [
                    'tenant_id' => $asset->tenant_id,
                    'asset_version_id' => $activeVersion?->id,
                    'storage_path' => $deterministicPath,
                    'status' => 'completed',
                    'error' => null,
                    'rendered_at' => now(),
                ]
            );
            $pageCount = (int) ($asset->pdf_page_count ?? 0);
            if ($pageCount > 0) {
                $this->updateExtractionCompletionFlag($asset, $versionNumber, $pageCount);
            }
            return;
        }

        // Already failed in a previous attempt — don't retry (stops FAIL → retry → FAIL loop)
        $existing = AssetPdfPage::query()
            ->where('asset_id', $asset->id)
            ->where('version_number', $versionNumber)
            ->where('page_number', $page)
            ->first();
        if ($existing && $existing->status === 'failed') {
            Log::info('[PdfPageRenderJob] Skipping retry - page already marked failed', [
                'asset_id' => $asset->id,
                'page' => $page,
            ]);
            return;
        }

        $pageRecord = AssetPdfPage::updateOrCreate(
            [
                'asset_id' => $asset->id,
                'version_number' => $versionNumber,
                'page_number' => $page,
            ],
            [
                'tenant_id' => $asset->tenant_id,
                'asset_version_id' => $activeVersion?->id,
                'status' => 'processing',
                'error' => null,
            ]
        );

        $tempPdfPath = null;
        $renderedPath = null;
        try {
            $tempPdfPath = $pdfPageRenderingService->downloadSourcePdfToTemp($asset, $activeVersion);
            $pageCount = (int) ($asset->pdf_page_count ?? 0);

            if ($pageCount < 1) {
                $pageCount = $pdfPageRenderingService->detectPageCount($tempPdfPath);
                $asset->forceFill([
                    'pdf_page_count' => $pageCount,
                    'pdf_pages_rendered' => false,
                ])->save();
            }

            if ($page > $pageCount) {
                $pageRecord->update([
                    'status' => 'failed',
                    'error' => "Requested page {$page} exceeds page count {$pageCount}.",
                ]);

                return;
            }

            $rendered = $pdfPageRenderingService->renderPageToWebp(
                $tempPdfPath,
                $page,
                [],
                true
            );
            $renderedPath = $rendered['local_path'];
            $storagePath = $pdfPageRenderingService->uploadRenderedPage(
                $asset,
                $activeVersion,
                $page,
                $renderedPath,
                $rendered['mime_type']
            );

            $pageRecord->update([
                'asset_version_id' => $activeVersion?->id,
                'storage_path' => $storagePath,
                'width' => $rendered['width'],
                'height' => $rendered['height'],
                'size_bytes' => $rendered['size_bytes'],
                'mime_type' => $rendered['mime_type'],
                'status' => 'completed',
                'error' => null,
                'rendered_at' => now(),
            ]);

            $this->updateExtractionCompletionFlag($asset, $versionNumber, $pageCount);

        } catch (\Throwable $e) {
            Log::error('[PdfPageRenderJob] Failed to render PDF page', [
                'asset_id' => $asset->id,
                'asset_version_id' => $activeVersion?->id,
                'page' => $page,
                'error' => $e->getMessage(),
            ]);

            $pageRecord->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            if ($renderedPath && file_exists($renderedPath)) {
                @unlink($renderedPath);
            }
            if ($tempPdfPath && file_exists($tempPdfPath)) {
                @unlink($tempPdfPath);
            }
        }
    }

    protected function updateExtractionCompletionFlag(Asset $asset, int $versionNumber, int $pageCount): void
    {
        if ($pageCount < 1) {
            return;
        }

        $completedCount = AssetPdfPage::query()
            ->where('asset_id', $asset->id)
            ->where('version_number', $versionNumber)
            ->where('status', 'completed')
            ->count();

        if ($completedCount >= $pageCount) {
            $asset->forceFill(['pdf_pages_rendered' => true])->save();
        }
    }
}
