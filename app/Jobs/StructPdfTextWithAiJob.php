<?php

namespace App\Jobs;

use App\Models\PdfTextExtraction;
use App\Services\PdfTextAiStructuringService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Runs AI structuring on a completed PDF text extraction.
 *
 * - Only runs when extraction is complete; service enforces guardrails.
 * - Uses getTextForAi(); stores result in pdf_text_ai_structures only.
 * - Never writes to asset or extraction metadata.
 * - Re-runnable: pass reprocess=true to replace existing structure.
 */
class StructPdfTextWithAiJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    /** @var int */
    public $extractionId;

    /** @var bool */
    public $reprocess;

    public function __construct(int $extractionId, bool $reprocess = false)
    {
        $this->extractionId = $extractionId;
        $this->reprocess = $reprocess;
        $this->onQueue(config('queue.pdf_processing_queue', 'pdf-processing'));
    }

    public function handle(PdfTextAiStructuringService $structuringService): void
    {
        $extraction = PdfTextExtraction::with(['asset.tenant'])->find($this->extractionId);
        if (!$extraction) {
            Log::warning('[StructPdfTextWithAiJob] Extraction not found', ['extraction_id' => $this->extractionId]);
            return;
        }

        $structuringService->run($extraction, $this->reprocess);
    }
}
