<?php

namespace App\Services\BrandIntelligence\Dimensions;

use App\Enums\BrandAlignmentState;
use App\Enums\DimensionStatus;

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
     * }
     */
    public function derive(array $dimensions, array $weights): array
    {
        $weightedScore = 0.0;
        $weightedConfidence = 0.0;
        $totalEvaluableWeight = 0.0;
        $totalOriginalWeight = 0.0;
        $evaluableCount = 0;
        $hardEvidenceCount = 0;

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
        }

        $overallConfidence = $totalEvaluableWeight > 0.001
            ? $weightedConfidence / $totalEvaluableWeight
            : 0.0;

        $evaluableProportion = $totalOriginalWeight > 0.001
            ? $totalEvaluableWeight / $totalOriginalWeight
            : 0.0;

        // Cap confidence by evaluable proportion so thin evaluations don't appear overly certain
        $overallConfidence = min($overallConfidence, $evaluableProportion + 0.2);
        $overallConfidence = max(0.0, min(1.0, round($overallConfidence, 4)));

        $weightedScore = max(0.0, min(1.0, round($weightedScore, 4)));
        $evaluableProportion = round($evaluableProportion, 4);

        $rating = $this->deriveRating($weightedScore, $overallConfidence, $evaluableCount, $hardEvidenceCount);
        $alignmentState = $this->deriveAlignmentState($rating, $overallConfidence, $evaluableCount, $hardEvidenceCount);

        $derivation = sprintf(
            'weighted_score=%.2f, confidence=%.2f, evaluable=%.2f, evaluable_dims=%d, hard_evidence_dims=%d -> rating %d (%s)',
            $weightedScore,
            $overallConfidence,
            $evaluableProportion,
            $evaluableCount,
            $hardEvidenceCount,
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
