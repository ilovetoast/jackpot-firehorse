<?php

namespace App\Services\BrandIntelligence\Dimensions;

use App\Enums\AlignmentDimension;
use App\Enums\DimensionStatus;
use App\Enums\EvidenceSource;
use App\Enums\EvidenceWeight;

final class DimensionResult
{
    public AlignmentDimension $dimension;
    public DimensionStatus $status;
    public float $score;
    public float $confidence;
    public EvidenceSource $primaryEvidenceSource;
    /** @var list<EvidenceItem> */
    public array $evidence;
    /** @var list<string> */
    public array $blockers;
    public bool $evaluable;
    public string $statusReason;

    /**
     * @param  list<EvidenceItem>  $evidence
     * @param  list<string>  $blockers
     */
    public function __construct(
        AlignmentDimension $dimension,
        DimensionStatus $status,
        float $score,
        float $confidence,
        EvidenceSource $primaryEvidenceSource,
        array $evidence,
        array $blockers,
        bool $evaluable,
        string $statusReason,
    ) {
        $this->dimension = $dimension;
        $this->status = $status;
        $this->score = $score;
        $this->confidence = $confidence;
        $this->primaryEvidenceSource = $primaryEvidenceSource;
        $this->evidence = $evidence;
        $this->blockers = $blockers;
        $this->evaluable = $evaluable;
        $this->statusReason = $statusReason;
    }

    public function toArray(): array
    {
        return [
            'dimension' => $this->dimension->value,
            'status' => $this->status->value,
            'score' => round($this->score, 4),
            'confidence' => round($this->confidence, 4),
            'primary_evidence_source' => $this->primaryEvidenceSource->value,
            'evidence' => array_map(fn (EvidenceItem $e) => $e->toArray(), $this->evidence),
            'blockers' => $this->blockers,
            'evaluable' => $this->evaluable,
            'status_reason' => $this->statusReason,
        ];
    }

    public function hasHardEvidence(): bool
    {
        foreach ($this->evidence as $e) {
            if ($e->weight === EvidenceWeight::HARD) {
                return true;
            }
        }

        return false;
    }

    public function hasSoftEvidence(): bool
    {
        foreach ($this->evidence as $e) {
            if ($e->weight === EvidenceWeight::SOFT) {
                return true;
            }
        }

        return false;
    }

    public static function notEvaluable(
        AlignmentDimension $dimension,
        string $reason,
        array $blockers = [],
        EvidenceSource $primarySource = EvidenceSource::NOT_EVALUABLE,
        array $evidence = [],
    ): self {
        return new self(
            dimension: $dimension,
            status: DimensionStatus::NOT_EVALUABLE,
            score: 0.0,
            confidence: 0.0,
            primaryEvidenceSource: $primarySource,
            evidence: $evidence,
            blockers: $blockers,
            evaluable: false,
            statusReason: $reason,
        );
    }

    public static function missingReference(
        AlignmentDimension $dimension,
        string $reason,
        array $blockers = [],
        array $evidence = [],
    ): self {
        return new self(
            dimension: $dimension,
            status: DimensionStatus::MISSING_REFERENCE,
            score: 0.0,
            confidence: 0.0,
            primaryEvidenceSource: EvidenceSource::CONFIGURATION_ONLY,
            evidence: $evidence,
            blockers: $blockers,
            evaluable: false,
            statusReason: $reason,
        );
    }
}
