<?php

namespace App\Services\BrandIntelligence\Dimensions;

use App\Enums\AlignmentDimension;
use App\Enums\DimensionStatus;
use App\Enums\EvidenceSource;
use App\Models\Asset;
use App\Models\Brand;

/**
 * Copy / Voice dimension.
 *
 * Critical distinction: missing text (not_evaluable) vs text present but weak fit (weak/fail).
 * Never use fail-style status when text was simply absent.
 */
final class CopyVoiceEvaluator implements DimensionEvaluatorInterface
{
    public function evaluate(Asset $asset, Brand $brand, EvaluationContext $context): DimensionResult
    {
        $hasOcr = $context->hasExtraction('ocr');
        $ocrText = $this->extractTextContent($asset);

        if (! $hasOcr && $ocrText === '') {
            return DimensionResult::notEvaluable(
                AlignmentDimension::COPY_VOICE,
                'No extractable text found',
                ['No OCR, PDF text, or transcript available for copy/voice evaluation'],
            );
        }

        if (mb_strlen(trim($ocrText), 'UTF-8') < 10) {
            return DimensionResult::notEvaluable(
                AlignmentDimension::COPY_VOICE,
                'Insufficient text for voice comparison',
                ['Very little text was extracted; not enough for meaningful voice alignment'],
            );
        }

        $brandHasVoice = $this->brandHasVoiceConfig($brand);

        if (! $brandHasVoice) {
            $evidence = [
                EvidenceItem::readiness(
                    EvidenceSource::EXTRACTED_TEXT,
                    sprintf('Text extracted (%d chars) but no brand voice configuration to compare against', mb_strlen($ocrText, 'UTF-8')),
                ),
            ];

            return DimensionResult::missingReference(
                AlignmentDimension::COPY_VOICE,
                'Text extracted but no brand voice/tone configuration available',
                ['Configure brand voice, tone, and messaging in brand DNA to enable copy evaluation'],
                $evidence,
            );
        }

        $evidence = [
            EvidenceItem::soft(
                EvidenceSource::EXTRACTED_TEXT,
                sprintf('Text extracted (%d chars); voice comparison available via AI analysis', mb_strlen($ocrText, 'UTF-8')),
            ),
        ];

        return new DimensionResult(
            dimension: AlignmentDimension::COPY_VOICE,
            status: DimensionStatus::PARTIAL,
            score: 0.5,
            confidence: 0.35,
            primaryEvidenceSource: EvidenceSource::EXTRACTED_TEXT,
            evidence: $evidence,
            blockers: [],
            evaluable: true,
            statusReason: 'Text extracted; basic voice comparison available but confidence is limited without dedicated AI copy analysis',
        );
    }

    /**
     * Ingest creative intelligence copy alignment results from the parallel AI pass.
     */
    public function enrichWithCreativeIntelligence(DimensionResult $base, ?array $copyAlignment, ?array $ebiTrace): DimensionResult
    {
        if ($copyAlignment === null || ($ebiTrace['copy_alignment_scored'] ?? false) !== true) {
            return $base;
        }

        $aiScore = $copyAlignment['score'] ?? null;
        $aiState = $copyAlignment['alignment_state'] ?? null;
        $aiConfidence = $copyAlignment['confidence'] ?? 0.0;
        $reasons = $copyAlignment['reasons'] ?? [];

        if (! is_numeric($aiScore) || $aiState === 'not_applicable') {
            return $base;
        }

        $evidence = $base->evidence;
        $reasonText = is_array($reasons) && count($reasons) > 0 ? implode('; ', array_slice($reasons, 0, 2)) : 'AI voice analysis';

        $evidence[] = EvidenceItem::soft(
            EvidenceSource::AI_ANALYSIS,
            sprintf('AI copy alignment: %s (score %.0f, confidence %.0f%%)', $aiState, $aiScore, $aiConfidence * 100),
        );

        $normalizedScore = is_numeric($aiScore) ? max(0.0, min(1.0, (float) $aiScore / 100.0)) : 0.5;
        $confidence = max(0.0, min(1.0, (float) $aiConfidence));

        if ($normalizedScore >= 0.65 && $confidence >= 0.4) {
            $status = DimensionStatus::ALIGNED;
        } elseif ($normalizedScore >= 0.4) {
            $status = DimensionStatus::PARTIAL;
        } elseif ($normalizedScore >= 0.2) {
            $status = DimensionStatus::WEAK;
        } else {
            $status = DimensionStatus::FAIL;
        }

        $statusReason = match ($status) {
            DimensionStatus::ALIGNED => 'Extracted copy appears to align with brand voice',
            DimensionStatus::PARTIAL => 'Extracted copy suggests partial brand voice alignment',
            DimensionStatus::WEAK => 'Extracted copy shows limited brand voice alignment',
            DimensionStatus::FAIL => 'Extracted copy appears to diverge from brand voice direction',
            default => $base->statusReason,
        };

        return new DimensionResult(
            dimension: AlignmentDimension::COPY_VOICE,
            status: $status,
            score: $normalizedScore,
            confidence: $confidence,
            primaryEvidenceSource: EvidenceSource::AI_ANALYSIS,
            evidence: $evidence,
            blockers: $status === DimensionStatus::FAIL
                ? ['Extracted copy does not align with configured brand voice direction']
                : $base->blockers,
            evaluable: true,
            statusReason: $statusReason,
        );
    }

    private function extractTextContent(Asset $asset): string
    {
        $parts = [];
        $meta = is_array($asset->metadata ?? null) ? $asset->metadata : [];

        foreach (['extracted_text', 'ocr_text', 'vision_ocr', 'detected_text'] as $k) {
            if (! empty($meta[$k]) && is_string($meta[$k])) {
                $parts[] = $meta[$k];
            }
        }

        if (\Illuminate\Support\Facades\Schema::hasTable('pdf_text_extractions')) {
            $ext = \App\Models\PdfTextExtraction::query()
                ->where('asset_id', $asset->id)
                ->orderByDesc('id')
                ->first();
            if ($ext && is_string($ext->extracted_text ?? null) && trim($ext->extracted_text) !== '') {
                $parts[] = $ext->extracted_text;
            }
        }

        return trim(implode("\n", array_filter($parts)));
    }

    private function brandHasVoiceConfig(Brand $brand): bool
    {
        $brand->loadMissing('brandModel.activeVersion');
        $payload = $brand->brandModel?->activeVersion?->model_payload ?? [];

        $personality = is_array($payload['personality'] ?? null) ? $payload['personality'] : [];
        if (! empty($personality['voice']) || ! empty($personality['tone']) || ! empty($personality['values'])) {
            return true;
        }

        $rules = is_array($payload['scoring_rules'] ?? null) ? $payload['scoring_rules'] : [];

        return ! empty($rules['tone_keywords']) || ! empty($rules['banned_phrases']);
    }
}
