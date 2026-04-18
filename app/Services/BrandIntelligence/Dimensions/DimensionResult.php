<?php

namespace App\Services\BrandIntelligence\Dimensions;

use App\Enums\AlignmentDimension;
use App\Enums\DimensionReasonCode;
use App\Enums\DimensionStatus;
use App\Enums\EvidenceSource;
use App\Enums\EvidenceWeight;
use App\Enums\SignalFamily;

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
    public ?DimensionReasonCode $reasonCode;

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
        ?DimensionReasonCode $reasonCode = null,
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
        $this->reasonCode = $reasonCode;
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
            'reason_code' => $this->reasonCode?->value,
            'signal_families' => array_map(
                static fn (SignalFamily $f): string => $f->value,
                $this->distinctSignalFamilies(),
            ),
        ];
    }

    /**
     * Distinct signal families present in this result.
     *
     * Only HARD and SOFT evidence counts toward diversity -- readiness-only
     * evidence is excluded because configuration / metadata hints cannot
     * meaningfully corroborate a score.
     *
     * @return list<SignalFamily>
     */
    public function distinctSignalFamilies(): array
    {
        $seen = [];
        foreach ($this->evidence as $e) {
            if ($e->weight === EvidenceWeight::READINESS) {
                continue;
            }
            $fam = SignalFamily::fromEvidenceSource($e->type);
            $seen[$fam->value] = $fam;
        }

        return array_values($seen);
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
        ?DimensionReasonCode $reasonCode = null,
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
            reasonCode: $reasonCode,
        );
    }

    public static function missingReference(
        AlignmentDimension $dimension,
        string $reason,
        array $blockers = [],
        array $evidence = [],
        ?DimensionReasonCode $reasonCode = null,
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
            reasonCode: $reasonCode,
        );
    }
}
