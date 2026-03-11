<?php

namespace App\Services\BrandDNA;

/**
 * Derives approximate progress from pipeline state for the Brand Guidelines processing UI.
 * Uses weighted stages; prefers believable partial progress over fake precision.
 */
class ResearchProgressService
{
    /** Stage weights sum to 100 */
    private const WEIGHT_UPLOAD = 5;
    private const WEIGHT_TEXT = 15;
    private const WEIGHT_PAGE_RENDER = 20;
    private const WEIGHT_VISUAL = 35;
    private const WEIGHT_FUSION = 15;
    private const WEIGHT_FINALIZE = 10;

    /**
     * Compute processing_progress for the research insights / builder response.
     *
     * @param  array{pipeline_status?: array, pdf?: array}  $context  Data from researchInsights (pdf, pipeline_status, etc.)
     * @param  object|null  $visionBatch  BrandPdfVisionExtraction or null
     * @param  object|null  $guidelinesPdfAsset  Asset or null
     */
    public function compute(
        array $context,
        ?object $visionBatch,
        ?object $guidelinesPdfAsset
    ): array {
        $pipeline = $context['pipeline_status'] ?? [];
        $pdf = $context['pdf'] ?? [];
        $hasPdf = $guidelinesPdfAsset !== null;

        $overallPercent = 0;
        $currentStage = 'text_extraction';
        $stages = $this->buildStages($pipeline, $pdf, $visionBatch, $hasPdf);

        foreach ($stages as $stage) {
            if ($stage['status'] === 'complete') {
                $overallPercent += $this->weightForStage($stage['key']);
                $currentStage = $stage['key'];
            } elseif ($stage['status'] === 'processing') {
                $currentStage = $stage['key'];
                $overallPercent += (int) round($this->weightForStage($stage['key']) * ($stage['percent'] / 100));
                break;
            }
        }

        $pages = $this->computePages($visionBatch, $pdf, $hasPdf);

        return [
            'overall_percent' => min(100, $overallPercent),
            'current_stage' => $currentStage,
            'stages' => $stages,
            'pages' => $pages,
        ];
    }

    private function weightForStage(string $key): int
    {
        return match ($key) {
            'text_extraction' => self::WEIGHT_UPLOAD + self::WEIGHT_TEXT,
            'page_rendering' => self::WEIGHT_PAGE_RENDER,
            'visual_extraction' => self::WEIGHT_VISUAL,
            'fusion' => self::WEIGHT_FUSION,
            'finalizing' => self::WEIGHT_FINALIZE,
            default => 0,
        };
    }

    protected function buildStages(array $pipeline, array $pdf, ?object $visionBatch, bool $hasPdf): array
    {
        $textComplete = (bool) ($pipeline['text_extraction_complete'] ?? false);
        $pdfRenderComplete = (bool) ($pipeline['pdf_render_complete'] ?? true);
        $pageClassComplete = (bool) ($pipeline['page_classification_complete'] ?? false);
        $pageExtractComplete = (bool) ($pipeline['page_extraction_complete'] ?? false);
        $fusionComplete = (bool) ($pipeline['fusion_complete'] ?? false);
        $snapshotPersisted = (bool) ($pipeline['snapshot_persisted'] ?? false);
        $suggestionsReady = (bool) ($pipeline['suggestions_ready'] ?? false);
        $coherenceReady = (bool) ($pipeline['coherence_ready'] ?? false);
        $alignmentReady = (bool) ($pipeline['alignment_ready'] ?? false);
        $researchFinalized = (bool) ($pipeline['research_finalized'] ?? false);

        $pagesTotal = (int) ($pdf['pages_total'] ?? 0);
        $pagesProcessed = (int) ($pdf['pages_processed'] ?? 0);

        // When research_finalized, all stages must be complete (no bouncing)
        if ($researchFinalized) {
            return [
                ['key' => 'text_extraction', 'label' => 'PDF text extraction', 'status' => 'complete', 'percent' => 100],
                ['key' => 'page_rendering', 'label' => 'Page rendering', 'status' => 'complete', 'percent' => 100],
                ['key' => 'visual_extraction', 'label' => 'Visual page analysis', 'status' => 'complete', 'percent' => 100],
                ['key' => 'fusion', 'label' => 'Research fusion', 'status' => 'complete', 'percent' => 100],
                ['key' => 'finalizing', 'label' => 'Finalizing insights', 'status' => 'complete', 'percent' => 100],
            ];
        }

        $stages = [];

        // 1. Text extraction — cannot be processing until started
        $textStatus = $textComplete ? 'complete' : ($hasPdf ? 'processing' : 'pending');
        $stages[] = [
            'key' => 'text_extraction',
            'label' => 'PDF text extraction',
            'status' => $textStatus,
            'percent' => $textComplete ? 100 : ($hasPdf ? 50 : 0),
        ];

        // 2. Page rendering — cannot start until text complete
        $renderComplete = $pdfRenderComplete || ($visionBatch && $pagesTotal > 0);
        $renderStatus = $renderComplete ? 'complete' : ($textComplete && $visionBatch ? 'processing' : 'pending');
        $stages[] = [
            'key' => 'page_rendering',
            'label' => 'Page rendering',
            'status' => $renderStatus,
            'percent' => $renderComplete ? 100 : ($renderStatus === 'processing' ? 50 : 0),
        ];

        // 3. Visual extraction — cannot start until page render complete
        $visualPercent = 0;
        if ($pagesTotal > 0) {
            $visualPercent = (int) round(min(100, ($pagesProcessed / $pagesTotal) * 100));
        } elseif ($pageExtractComplete) {
            $visualPercent = 100;
        }
        $visualStatus = $pageExtractComplete ? 'complete' : ($renderComplete && $visionBatch ? 'processing' : 'pending');
        $stages[] = [
            'key' => 'visual_extraction',
            'label' => 'Visual page analysis',
            'status' => $visualStatus,
            'percent' => $visualPercent,
        ];

        // 4. Fusion — cannot start until visual complete; complete when snapshot persisted
        $fusionStatus = ($fusionComplete && $snapshotPersisted) ? 'complete' : ($pageExtractComplete ? 'processing' : 'pending');
        $stages[] = [
            'key' => 'fusion',
            'label' => 'Research fusion',
            'status' => $fusionStatus,
            'percent' => $snapshotPersisted ? 100 : ($fusionStatus === 'processing' ? 60 : 0),
        ];

        // 5. Finalizing — only processing during true end window before research_finalized
        $finalizeComplete = $suggestionsReady && $coherenceReady && $alignmentReady && $researchFinalized;
        $finalizeStatus = $finalizeComplete ? 'complete' : ($snapshotPersisted && ! $finalizeComplete ? 'processing' : 'pending');
        $stages[] = [
            'key' => 'finalizing',
            'label' => 'Finalizing insights',
            'status' => $finalizeStatus,
            'percent' => $finalizeComplete ? 100 : ($finalizeStatus === 'processing' ? 70 : 0),
        ];

        return $stages;
    }

    private function computePages(?object $visionBatch, array $pdf, bool $hasPdf): array
    {
        $total = (int) ($pdf['pages_total'] ?? 0);
        $processed = (int) ($pdf['pages_processed'] ?? 0);

        if (! $hasPdf || $total === 0) {
            return [
                'total' => 0,
                'rendered' => 0,
                'classified' => 0,
                'extracted' => 0,
            ];
        }

        // Use vision batch / pdf state for page counts (avoids DB dependency for unit tests)
        $classified = $processed;
        $extracted = $processed;

        return [
            'total' => $total,
            'rendered' => $total,
            'classified' => min($classified, $total),
            'extracted' => min($extracted, $total),
        ];
    }
}
