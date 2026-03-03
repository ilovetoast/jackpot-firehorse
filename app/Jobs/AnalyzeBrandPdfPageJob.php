<?php

namespace App\Jobs;

use App\Models\BrandPdfPageExtraction;
use App\Models\BrandPdfVisionExtraction;
use App\Services\BrandDNA\Extraction\VisionExtractionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AnalyzeBrandPdfPageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(
        public int $pageExtractionId
    ) {
        $this->onQueue(config('queue.pdf_processing_queue', 'pdf-processing'));
    }

    public function handle(VisionExtractionService $visionService): void
    {
        $pageExt = BrandPdfPageExtraction::find($this->pageExtractionId);
        if (! $pageExt) {
            return;
        }

        if ($pageExt->status === BrandPdfPageExtraction::STATUS_CANCELLED) {
            return;
        }

        $visionBatch = BrandPdfVisionExtraction::where('batch_id', $pageExt->batch_id)->first();
        if (! $visionBatch || $visionBatch->early_complete) {
            $pageExt->update(['status' => BrandPdfPageExtraction::STATUS_CANCELLED]);
            return;
        }

        $pageExt->update(['status' => BrandPdfPageExtraction::STATUS_PROCESSING]);

        $imagePath = $pageExt->extraction_json['_temp_image_path'] ?? null;
        if (! $imagePath || ! file_exists($imagePath)) {
            $pageExt->update([
                'status' => BrandPdfPageExtraction::STATUS_FAILED,
                'error_message' => 'Image path missing or file not found',
            ]);
            return;
        }

        try {
            $extraction = $visionService->extractFromImage($imagePath);
            $pageExt->update([
                'extraction_json' => array_merge($extraction, [
                    'page_number' => $pageExt->page_number,
                    'confidence' => $extraction['confidence'] ?? 0.5,
                ]),
                'status' => BrandPdfPageExtraction::STATUS_COMPLETED,
                'error_message' => null,
            ]);

            $visionBatch->increment('pages_processed');
            $signals = $this->countSignals($extraction);
            $visionBatch->update(['signals_detected' => $visionBatch->signals_detected + $signals]);

            $visionBatch->refresh();
            $shouldMerge = false;
            if ($this->hasEarlyExitSignals($visionBatch)) {
                $visionBatch->update(['early_complete' => true]);
                Log::info('[AnalyzeBrandPdfPageJob] Early exit triggered', ['batch_id' => $visionBatch->batch_id]);
                $shouldMerge = true;
            } elseif ($visionBatch->pages_processed >= $visionBatch->pages_total) {
                $shouldMerge = true;
            }
            if ($shouldMerge) {
                MergeBrandPdfExtractionJob::dispatch($visionBatch->batch_id);
            }
        } catch (\Throwable $e) {
            $pageExt->update([
                'status' => BrandPdfPageExtraction::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);
            Log::error('[AnalyzeBrandPdfPageJob] Page analysis failed', [
                'page_extraction_id' => $pageExt->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    protected function countSignals(array $extraction): int
    {
        $c = 0;
        foreach (['identity', 'personality', 'visual'] as $section) {
            $data = $extraction[$section] ?? [];
            foreach ($data as $v) {
                if ($v !== null && $v !== '' && $v !== []) {
                    $c++;
                }
            }
        }
        return $c;
    }

    protected function hasEarlyExitSignals(BrandPdfVisionExtraction $batch): bool
    {
        $pages = BrandPdfPageExtraction::where('batch_id', $batch->batch_id)
            ->where('status', BrandPdfPageExtraction::STATUS_COMPLETED)
            ->get();

        $merged = $this->mergePageExtractions($pages);
        $identity = $merged['identity'] ?? [];
        $personality = $merged['personality'] ?? [];
        $visual = $merged['visual'] ?? [];

        $hasArchetype = ! empty($personality['primary_archetype']);
        $hasMission = ! empty($identity['mission']);
        $toneCount = count($personality['tone_keywords'] ?? []);
        $colorCount = count($visual['primary_colors'] ?? []);
        $fontCount = count($visual['fonts'] ?? []);

        return $hasArchetype && $hasMission && $toneCount >= 3 && $colorCount >= 1 && $fontCount >= 1;
    }

    protected function mergePageExtractions($pages): array
    {
        $result = [
            'identity' => ['mission' => null, 'vision' => null, 'positioning' => null],
            'personality' => ['primary_archetype' => null, 'tone_keywords' => [], 'traits' => []],
            'visual' => ['primary_colors' => [], 'fonts' => []],
        ];
        foreach ($pages as $p) {
            $ext = $p->extraction_json ?? [];
            foreach (['identity', 'personality', 'visual'] as $section) {
                $data = $ext[$section] ?? [];
                if (! is_array($data)) {
                    continue;
                }
                foreach ($data as $k => $v) {
                    if ($v === null || $v === '') {
                        continue;
                    }
                    if (in_array($k, ['mission', 'vision', 'positioning', 'primary_archetype']) && $result[$section][$k] === null) {
                        $result[$section][$k] = $v;
                    }
                    if (in_array($k, ['tone_keywords', 'traits', 'primary_colors', 'fonts']) && is_array($v)) {
                        $result[$section][$k] = array_values(array_unique(array_merge($result[$section][$k] ?? [], $v)));
                    }
                }
            }
        }
        return $result;
    }
}
