<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Services\PdfPageRenderingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FullPdfExtractionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public $backoff = [60, 300];

    public function __construct(
        public readonly string $assetId,
        public readonly int $page,
        public readonly bool $adminOverride = false
    ) {
        $this->onQueue((string) config('pdf.queue', config('queue.pdf_processing_queue', 'pdf-processing')));
    }

    public function handle(PdfPageRenderingService $pdfService): void
    {
        $asset = Asset::find($this->assetId);
        if (! $asset) {
            return;
        }

        if (! $pdfService->isPdfAsset($asset)) {
            return;
        }

        $pageCount = $pdfService->getPdfPageCount($asset);

        $adminThreshold = (int) config('pdf.max_full_extract_without_admin', 100);
        if (! $this->adminOverride && $pageCount > $adminThreshold) {
            throw new \RuntimeException(
                "Full extraction blocked: page count {$pageCount} exceeds non-admin threshold {$adminThreshold}."
            );
        }

        if ($this->page > $pageCount) {
            return;
        }

        $pdfService->renderPage($asset, $this->page);
    }
}
