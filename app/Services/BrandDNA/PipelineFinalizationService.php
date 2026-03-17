<?php

namespace App\Services\BrandDNA;

use App\Models\BrandPipelineRun;
use App\Models\BrandPipelineSnapshot;
use Illuminate\Support\Facades\Log;

/**
 * Computes research_finalized and pipeline_status for Brand DNA builder.
 * Simplified: no per-page tracking. Claude single-pass or text path.
 */
class PipelineFinalizationService
{
    /**
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
            'text_extraction_complete' => true,
            'snapshot_persisted' => false,
            'suggestions_ready' => false,
            'coherence_ready' => false,
            'alignment_ready' => false,
            'research_finalized' => false,
        ];

        $pdfProcessing = false;
        $pipelineProcessing = false;
        $latestRun = null;
        $latestSnapshot = null;

        if ($guidelinesPdfAsset) {
            $latestRun = BrandPipelineRun::where('asset_id', $guidelinesPdfAsset->id)
                ->where('brand_id', $brandId)
                ->where('brand_model_version_id', $draftId)
                ->with('snapshot')
                ->latest()
                ->first();

            if ($latestRun) {
                $runComplete = in_array($latestRun->status, [
                    BrandPipelineRun::STATUS_COMPLETED,
                    BrandPipelineRun::STATUS_FAILED,
                ]);

                $status['text_extraction_complete'] = $runComplete;
                $pipelineProcessing = ! $runComplete;

                $latestSnapshot = $latestRun->snapshot;
            } else {
                $status['text_extraction_complete'] = false;
                $pdfProcessing = true;
            }
        }

        if ($latestSnapshot === null && ! $latestRun) {
            $latestSnapshot = BrandPipelineSnapshot::where('brand_id', $brandId)
                ->where('brand_model_version_id', $draftId)
                ->where('status', BrandPipelineSnapshot::STATUS_COMPLETED)
                ->latest()
                ->first();
        }

        $runningSnapshot = BrandPipelineSnapshot::where('brand_id', $brandId)
            ->where('brand_model_version_id', $draftId)
            ->whereIn('status', ['pending', 'running'])
            ->latest()
            ->first();

        $snapshotExists = $latestSnapshot !== null;
        $status['snapshot_persisted'] = $snapshotExists;

        $suggestionsReady = $snapshotExists && $latestSnapshot && is_array($latestSnapshot->suggestions);
        $status['suggestions_ready'] = $suggestionsReady;

        $coherenceReady = $snapshotExists && $latestSnapshot && is_array($latestSnapshot->coherence);
        $status['coherence_ready'] = $coherenceReady;

        $alignmentReady = $snapshotExists && $latestSnapshot && isset($latestSnapshot->alignment);
        $status['alignment_ready'] = $alignmentReady;

        $websiteOrSocial = $hasWebsiteUrl || $hasSocialUrls;
        $hasPdf = $guidelinesPdfAsset !== null;

        $allSourcesComplete = true;
        if ($hasPdf) {
            $allSourcesComplete = $allSourcesComplete && ! $pipelineProcessing && ! $pdfProcessing;
        }
        if ($websiteOrSocial) {
            $allSourcesComplete = $allSourcesComplete && $runningSnapshot === null;
        }

        $runFailed = $latestRun && $latestRun->status === BrandPipelineRun::STATUS_FAILED;
        $researchFinalized = ! $pdfProcessing
            && ! $pipelineProcessing
            && ! $runFailed
            && $snapshotExists
            && $suggestionsReady
            && $coherenceReady
            && $alignmentReady
            && $allSourcesComplete
            && $runningSnapshot === null;

        $status['research_finalized'] = $researchFinalized;

        Log::channel('pipeline')->info('[PipelineFinalizationService] Progression gate', [
            'event' => $researchFinalized ? 'GATE_ALLOW' : 'GATE_BLOCK',
            'draft_id' => $draftId,
            'research_finalized' => $researchFinalized,
            'run_id' => $latestRun?->id,
            'run_status' => $latestRun?->status,
            'extraction_mode' => $latestRun?->extraction_mode,
            'pages_processed' => $latestRun?->pages_processed,
            'pages_total' => $latestRun?->pages_total,
            'run_failed' => $runFailed,
        ]);

        return [
            'pipeline_status' => $status,
            'research_finalized' => $researchFinalized,
        ];
    }
}
