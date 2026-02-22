<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Services\PdfPageRenderingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PdfPageRenderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [30, 120, 300];

    public function __construct(
        public readonly string $assetId,
        public readonly int $page,
        public readonly bool $force = false
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

        if ($asset->pdf_unsupported_large) {
            Log::warning('[PdfPageRenderJob] Skipping render for unsupported-large PDF', [
                'asset_id' => $asset->id,
                'page' => $this->page,
            ]);

            return;
        }

        $pdfService->renderPage($asset, $this->page, $this->force);
    }
}
