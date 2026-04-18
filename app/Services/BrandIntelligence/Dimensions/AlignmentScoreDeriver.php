<?php

namespace App\Services\BrandIntelligence\Dimensions;

use App\Enums\BrandAlignmentState;
use App\Enums\DimensionStatus;
use App\Enums\SignalFamily;

/**
 * Derives the overall alignment score, 1-4 rating, confidence, and alignment state
 * from the 6 dimension results and their weights.
 *
 * All thresholds are calibration defaults, subject to tuning after sample-set validation.
 */
final class AlignmentScoreDeriver
{
    /**
     * @param  array<string, DimensionResult>  $dimensions
     * @param  array<string, float>  $weights  Post-redistribution weights
     * @return array{
     *   weighted_score: float,
     *   overall_confidence: float,
     *   evaluable_proportion: float,
     *   rating: int,
     *   rating_derivation: string,
     *   alignment_state: BrandAlignmentState,
     *   signal_family_coverage: list<string>,
     *   confidence_dampeners: list<string>,
     * }
     */
    public function derive(array $dimensions, array $weights): array
    {
        $dampeners = [];

        // Per-dimension dampener: if a single evaluable dim has only one family
        // and that family is PIXEL_VISUAL, cap its confidence at 0.50. This
        // prevents correlated VLM fields from producing over-confident scores.
        foreach ($dimensions as $key => $result) {
            if (! $result->evaluable) {
                continue;
            }
            $fams = $result->distinctSignalFamilies();
            if (count($fams) === 1 && $fams[0] === SignalFamily::PIXEL_VISUAL && $result->confidence > 0.50) {
                $dampeners[] = sprintf('%s: single-family pixel-visual cap (0.50)', $key);
                $dimensions[$key] = new DimensionResult(
                    dimension: $result->dimension,
                    status: $result->status,
                    score: $result->score,
                    confidence: 0.50,
                    primaryEvidenceSource: $result->primaryEvidenceSource,
                    evidence: $result->evidence,
                    blockers: $result->blockers,
                    evaluable: $result->evaluable,
                    statusReason: $result->statusReason,
                    reasonCode: $result->reasonCode,
                );
            }
        }

        $weightedScore = 0.0;
        $weightedConfidence = 0.0;
        $totalEvaluableWeight = 0.0;
        $totalOriginalWeight = 0.0;
        $evaluableCount = 0;
        $hardEvidenceCount = 0;

        /** @var array<string, SignalFamily> $familyUnion */
        $familyUnion = [];

        foreach ($dimensions as $key => $result) {
            $w = $weights[$key] ?? 0.0;
            $totalOriginalWeight += $w;

            if (! $result->evaluable || $w < 0.001) {
                continue;
            }

            $weightedScore += $result->score * $w * $result->confidence;
            $weightedConfidence += $result->confidence * $w;
            $totalEvaluableWeight += $w;
            $evaluableCount++;

            if ($result->hasHardEvidence()) {
                $hardEvidenceCount++;
            }

            foreach ($result->distinctSignalFamilies() as $fam) {
                $familyUnion[$fam->value] = $fam;
            }
        }

        $overallConfidence = $totalEvaluableWeight > 0.001
            ? $weightedConfidence / $totalEvaluableWeight
            : 0.0;

        $evaluableProportion = $totalOriginalWeight > 0.001
            ? $totalEvaluableWeight / $totalOriginalWeight
            : 0.0;

        // Signal diversity dampener: cap overall confidence when evidence came
        // from too few distinct families. This stops us from trusting a score
        // built entirely from correlated VLM outputs or one OCR extraction.
        $familyCount = count($familyUnion);
        if ($familyCount < 2) {
            if ($overallConfidence > 0.55) {
                $dampeners[] = sprintf('overall: <2 signal families (cap 0.55)');
            }
            $overallConfidence = min($overallConfidence, 0.55);
        } elseif ($familyCount < 3 && ! isset($familyUnion[SignalFamily::REFERENCE_SIMILARITY->value])) {
            if ($overallConfidence > 0.70) {
                $dampeners[] = sprintf('overall: <3 families without reference similarity (cap 0.70)');
            }
            $overallConfidence = min($overallConfidence, 0.70);
        }

        // Cap confidence by evaluable proportion so thin evaluations don't appear overly certain
        $overallConfidence = min($overallConfidence, $evaluableProportion + 0.2);
        $overallConfidence = max(0.0, min(1.0, round($overallConfidence, 4)));

        $weightedScore = max(0.0, min(1.0, round($weightedScore, 4)));
        $evaluableProportion = round($evaluableProportion, 4);

        $rating = $this->deriveRating($weightedScore, $overallConfidence, $evaluableCount, $hardEvidenceCount);
        $alignmentState = $this->deriveAlignmentState($rating, $overallConfidence, $evaluableCount, $hardEvidenceCount);

        $derivation = sprintf(
            'weighted_score=%.2f, confidence=%.2f, evaluable=%.2f, evaluable_dims=%d, hard_evidence_dims=%d, families=%d -> rating %d (%s)',
            $weightedScore,
            $overallConfidence,
            $evaluableProportion,
            $evaluableCount,
            $hardEvidenceCount,
            $familyCount,
            $rating,
            $this->ratingLabel($rating),
        );

        return [
            'weighted_score' => $weightedScore,
            'overall_confidence' => $overallConfidence,
            'evaluable_proportion' => $evaluableProportion,
            'rating' => $rating,
            'rating_derivation' => $derivation,
            'alignment_state' => $alignmentState,
            'signal_family_coverage' => array_values(array_map(
                static fn (SignalFamily $f): string => $f->value,
                $familyUnion,
            )),
            'confidence_dampeners' => $dampeners,
        ];
    }

    /**
     * Calibration defaults -- subject to tuning after sample-set validation.
     */
    private function deriveRating(float $score, float $confidence, int $evaluableCount, int $hardEvidenceCount): int
    {
        if ($evaluableCount < 2) {
            return 1;
        }

        if ($score >= 0.75 && $confidence >= 0.5) {
            return 4;
        }
        if ($score >= 0.55 && $confidence >= 0.4) {
            return 3;
        }
        if ($score >= 0.35 || $hardEvidenceCount >= 1) {
            return 2;
        }

        return 1;
    }

    private function deriveAlignmentState(float $rating, float $confidence, int $evaluableCount, int $hardEvidenceCount): BrandAlignmentState
    {
        if ($evaluableCount < 2 || $hardEvidenceCount === 0) {
            return BrandAlignmentState::INSUFFICIENT_EVIDENCE;
        }

        if ($rating >= 4 || ($rating >= 3 && $confidence >= 0.6)) {
            return BrandAlignmentState::ON_BRAND;
        }
        if ($rating >= 2) {
            return BrandAlignmentState::PARTIAL_ALIGNMENT;
        }

        return BrandAlignmentState::OFF_BRAND;
    }

    private function ratingLabel(int $rating): string
    {
        return match ($rating) {
            4 => 'strong alignment',
            3 => 'solid alignment',
            2 => 'partial alignment',
            default => 'weak / insufficient',
        };
    }
}
