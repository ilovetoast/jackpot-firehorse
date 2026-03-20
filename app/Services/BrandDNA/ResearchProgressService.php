<?php

namespace App\Services\BrandDNA;

/**
 * Derives approximate progress from pipeline state for the Brand Guidelines processing UI.
 * Three-stage single-pass pipeline: Upload/prepare → Claude analysis → Finalize snapshot.
 */
class ResearchProgressService
{
    private const WEIGHT_UPLOAD = 5;
    private const WEIGHT_ANALYZING = 80;
    private const WEIGHT_FINALIZE = 15;

    public function compute(
        array $context,
        ?object $pipelineRun,
        ?object $guidelinesPdfAsset
    ): array {
        $pipeline = $context['pipeline_status'] ?? [];
        $hasPdf = $guidelinesPdfAsset !== null;

        $overallPercent = 0;
        $currentStage = 'text_extraction';
        $stages = $this->buildStages($pipeline, $pipelineRun, $hasPdf);

        foreach ($stages as $stage) {
            if ($stage['status'] === 'complete') {
                $overallPercent += $this->weightForStage($stage['key']);
                $currentStage = $stage['key'];
            } elseif ($stage['status'] === 'processing') {
                $currentStage = $stage['key'];
                $overallPercent += (int) round($this->weightForStage($stage['key']) * ($stage['percent'] / 100));
                break;
            } elseif ($stage['status'] === 'failed') {
                $currentStage = $stage['key'];
                break;
            }
        }

        return [
            'overall_percent' => min(100, $overallPercent),
            'current_stage' => $currentStage,
            'stages' => $stages,
        ];
    }

    private function weightForStage(string $key): int
    {
        return match ($key) {
            'text_extraction' => self::WEIGHT_UPLOAD,
            'analyzing' => self::WEIGHT_ANALYZING,
            'finalizing' => self::WEIGHT_FINALIZE,
            default => 0,
        };
    }

    protected function buildStages(array $pipeline, ?object $pipelineRun, bool $hasPdf): array
    {
        $textComplete = (bool) ($pipeline['text_extraction_complete'] ?? false);
        $snapshotPersisted = (bool) ($pipeline['snapshot_persisted'] ?? false);
        $suggestionsReady = (bool) ($pipeline['suggestions_ready'] ?? false);
        $coherenceReady = (bool) ($pipeline['coherence_ready'] ?? false);
        $alignmentReady = (bool) ($pipeline['alignment_ready'] ?? false);
        $researchFinalized = (bool) ($pipeline['research_finalized'] ?? false);

        $runFailed = $pipelineRun && ($pipelineRun->status ?? null) === 'failed';

        // Fresh draft / after "Start over": no PDF, no pipeline run, no snapshot yet — avoid
        // PipelineFinalizationService default flags showing "Preparing document" complete + fake analyzing.
        if (! $researchFinalized && ! $hasPdf && ! $pipelineRun && ! $snapshotPersisted) {
            return [
                ['key' => 'text_extraction', 'label' => 'Preparing document', 'status' => 'pending', 'percent' => 0],
                ['key' => 'analyzing', 'label' => 'Analyzing with AI', 'status' => 'pending', 'percent' => 0],
                ['key' => 'finalizing', 'label' => 'Finalizing insights', 'status' => 'pending', 'percent' => 0],
            ];
        }
        $runError = $runFailed ? ($pipelineRun->error_message ?? null) : null;

        if ($researchFinalized) {
            return [
                ['key' => 'text_extraction', 'label' => 'Document prepared', 'status' => 'complete', 'percent' => 100],
                ['key' => 'analyzing', 'label' => 'AI analysis complete', 'status' => 'complete', 'percent' => 100],
                ['key' => 'finalizing', 'label' => 'Insights finalized', 'status' => 'complete', 'percent' => 100],
            ];
        }

        $stages = [];

        if ($runFailed) {
            $failedInUpload = ! $textComplete;
            $stages[] = [
                'key' => 'text_extraction',
                'label' => 'Preparing document',
                'status' => $failedInUpload ? 'failed' : 'complete',
                'percent' => $failedInUpload ? 0 : 100,
                'error' => $failedInUpload ? $this->friendlyError($runError) : null,
            ];
            $stages[] = [
                'key' => 'analyzing',
                'label' => 'Analyzing with AI',
                'status' => $failedInUpload ? 'pending' : 'failed',
                'percent' => 0,
                'error' => ! $failedInUpload ? $this->friendlyError($runError) : null,
            ];
            $stages[] = [
                'key' => 'finalizing',
                'label' => 'Finalizing insights',
                'status' => 'pending',
                'percent' => 0,
            ];

            return $stages;
        }

        $textStatus = $textComplete ? 'complete' : ($hasPdf ? 'processing' : 'pending');
        $stages[] = [
            'key' => 'text_extraction',
            'label' => 'Preparing document',
            'status' => $textStatus,
            'percent' => $textComplete ? 100 : ($hasPdf ? 80 : 0),
        ];

        $analyzingComplete = $snapshotPersisted;
        $analyzingStatus = $analyzingComplete ? 'complete' : ($textComplete ? 'processing' : 'pending');
        $stages[] = [
            'key' => 'analyzing',
            'label' => 'Analyzing with AI',
            'status' => $analyzingStatus,
            'percent' => $analyzingComplete ? 100 : ($analyzingStatus === 'processing' ? 30 : 0),
        ];

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

    protected function friendlyError(?string $error): string
    {
        if (! $error) {
            return 'An unexpected error occurred during processing.';
        }

        if (str_contains($error, 'credit balance is too low')) {
            return 'AI service billing issue — please check your Anthropic API credits.';
        }
        if (str_contains($error, 'rate_limit') || str_contains($error, 'rate limit')) {
            return 'AI service rate limited — will retry automatically.';
        }
        if (str_contains($error, 'timeout') || str_contains($error, 'timed out')) {
            return 'Request timed out — the document may be too large. Try again.';
        }

        if (app()->environment('production')) {
            return 'An error occurred during AI processing. Please try again or contact support.';
        }

        return 'Processing failed: ' . mb_substr($error, 0, 120);
    }
}
