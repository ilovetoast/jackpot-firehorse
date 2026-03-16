<?php

namespace App\Services\BrandDNA;

use App\Models\BrandIngestionRecord;
use App\Models\BrandPdfVisionExtraction;
use App\Models\BrandResearchSnapshot;
use Illuminate\Support\Facades\Log;

/**
 * Computes research_finalized and pipeline_status for Brand DNA builder.
 * Gates Research Summary and Next button until all stages are complete.
 */
class ResearchFinalizationService
{
    /**
     * Compute pipeline status and research_finalized for a draft.
     *
     * @return array{pipeline_status: array, research_finalized: bool}
     */
    public function compute(
        int $brandId,
        int $draftId,
        ?object $guidelinesPdfAsset,
        bool $hasWebsiteUrl,
        bool $hasSocialUrls,
        int $brandMaterialCount
    ): array {
        $status = [
            'pdf_render_complete' => true,
            'page_classification_complete' => true,
            'page_extraction_complete' => true,
            'text_extraction_complete' => true,
            'fusion_complete' => true,
            'snapshot_persisted' => false,
            'suggestions_ready' => false,
            'coherence_ready' => false,
            'alignment_ready' => false,
            'research_finalized' => false,
        ];

        $pdfProcessing = false;
        $visionProcessing = false;
        $ingestionProcessing = false;

        if ($guidelinesPdfAsset) {
            $visionBatch = BrandPdfVisionExtraction::where('asset_id', $guidelinesPdfAsset->id)
                ->where('brand_id', $brandId)
                ->where('brand_model_version_id', $draftId)
                ->latest()
                ->first();

            if ($visionBatch) {
                $visionComplete = in_array($visionBatch->status, [
                    BrandPdfVisionExtraction::STATUS_COMPLETED,
                    BrandPdfVisionExtraction::STATUS_FAILED,
                ]);
                // Require ALL pages processed — no partial completion (guards against legacy early-exit runs)
                if ($visionComplete && $visionBatch->status === BrandPdfVisionExtraction::STATUS_COMPLETED) {
                    $allPagesDone = $visionBatch->pages_total > 0
                        && $visionBatch->pages_processed >= $visionBatch->pages_total;
                    $hasPageData = ! empty($visionBatch->extraction_json['page_classifications_json'] ?? null)
                        || ! empty($visionBatch->extraction_json['page_extractions_json'] ?? null)
                        || ! empty($visionBatch->extraction_json['page_analysis'] ?? null);
                    if (! $allPagesDone || ! $hasPageData) {
                        $visionComplete = false;
                    }
                }
                $status['page_classification_complete'] = $visionComplete;
                $status['page_extraction_complete'] = $visionComplete;
                $status['fusion_complete'] = $visionComplete;
                $visionProcessing = ! $visionComplete;
            } else {
                $extraction = $guidelinesPdfAsset->getLatestPdfTextExtractionForVersion($guidelinesPdfAsset->currentVersion?->id);
                if ($extraction) {
                    $textComplete = in_array($extraction->status, ['complete', 'failed']);
                    $status['text_extraction_complete'] = $textComplete;
                    $status['page_classification_complete'] = $textComplete;
                    $status['page_extraction_complete'] = $textComplete;
                    $status['fusion_complete'] = $textComplete;
                    $pdfProcessing = ! $textComplete;
                } else {
                    $status['pdf_render_complete'] = false;
                    $status['page_classification_complete'] = false;
                    $status['page_extraction_complete'] = false;
                    $status['text_extraction_complete'] = false;
                    $status['fusion_complete'] = false;
                    $pdfProcessing = true;
                }
            }
        }

        $ingestionRecords = BrandIngestionRecord::where('brand_id', $brandId)
            ->where('brand_model_version_id', $draftId)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();
        $ingestionProcessing = $ingestionRecords->contains(fn ($r) => $r->status === BrandIngestionRecord::STATUS_PROCESSING);

        $runningSnapshot = BrandResearchSnapshot::where('brand_id', $brandId)
            ->where('brand_model_version_id', $draftId)
            ->whereIn('status', ['pending', 'running'])
            ->latest()
            ->first();

        $latestSnapshot = BrandResearchSnapshot::where('brand_id', $brandId)
            ->where('brand_model_version_id', $draftId)
            ->where('status', 'completed')
            ->latest()
            ->first();

        $snapshotExists = $latestSnapshot !== null;
        $status['snapshot_persisted'] = $snapshotExists;

        // Verify snapshot has page data when vision path was used — prevents finalization with empty page analysis
        if ($snapshotExists && $guidelinesPdfAsset) {
            $visionBatch = BrandPdfVisionExtraction::where('asset_id', $guidelinesPdfAsset->id)
                ->where('brand_id', $brandId)
                ->where('brand_model_version_id', $draftId)
                ->where('status', BrandPdfVisionExtraction::STATUS_COMPLETED)
                ->latest()
                ->first();
            if ($visionBatch) {
                $snapshot = $latestSnapshot->snapshot ?? [];
                $hasPageAnalysis = ! empty($snapshot['page_analysis']);
                $hasClassifications = ! empty($latestSnapshot->page_classifications_json);
                $hasExtractions = ! empty($latestSnapshot->page_extractions_json);
                if (! $hasPageAnalysis && ! $hasClassifications && ! $hasExtractions) {
                    Log::warning('[ResearchFinalizationService] Detected incomplete vision data in snapshot', [
                        'draft_id' => $draftId,
                        'snapshot_id' => $latestSnapshot->id,
                    ]);
                    $snapshotExists = false;
                    $status['snapshot_persisted'] = false;
                }
            }
        }

        $suggestionsReady = $snapshotExists && is_array($latestSnapshot->suggestions);
        $status['suggestions_ready'] = $suggestionsReady;

        $coherenceReady = $snapshotExists && is_array($latestSnapshot->coherence);
        $status['coherence_ready'] = $coherenceReady;

        $alignmentReady = $snapshotExists && isset($latestSnapshot->alignment);
        $status['alignment_ready'] = $alignmentReady;

        $websiteOrSocial = $hasWebsiteUrl || $hasSocialUrls;
        $hasPdf = $guidelinesPdfAsset !== null;
        $hasMaterials = $brandMaterialCount > 0;

        $allSourcesComplete = true;
        if ($hasPdf) {
            $allSourcesComplete = $allSourcesComplete && ! $visionProcessing && ! $pdfProcessing;
        }
        if ($websiteOrSocial) {
            $allSourcesComplete = $allSourcesComplete && $runningSnapshot === null;
        }
        if ($hasMaterials) {
            $latestIngestion = $ingestionRecords->first();
            $allSourcesComplete = $allSourcesComplete && $latestIngestion
                && $latestIngestion->status !== BrandIngestionRecord::STATUS_PROCESSING;
        }

        $researchFinalized = ! $pdfProcessing
            && ! $visionProcessing
            && ! $ingestionProcessing
            && $snapshotExists
            && $suggestionsReady
            && $coherenceReady
            && $alignmentReady
            && $allSourcesComplete
            && $runningSnapshot === null;

        $status['research_finalized'] = $researchFinalized;

        return [
            'pipeline_status' => $status,
            'research_finalized' => $researchFinalized,
        ];
    }
}
