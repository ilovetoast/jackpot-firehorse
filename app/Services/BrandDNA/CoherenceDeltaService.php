<?php

namespace App\Services\BrandDNA;

/**
 * Calculates coherence deltas between two coherence snapshots.
 * Does not mutate inputs.
 */
class CoherenceDeltaService
{
    public function calculate(array $previousCoherence, array $currentCoherence): array
    {
        $prevOverall = $previousCoherence['overall'] ?? [];
        $currOverall = $currentCoherence['overall'] ?? [];
        $prevScore = (int) ($prevOverall['score'] ?? 0);
        $currScore = (int) ($currOverall['score'] ?? 0);
        $overallDelta = $currScore - $prevScore;

        $sectionDeltas = [];
        $prevSections = $previousCoherence['sections'] ?? [];
        $currSections = $currentCoherence['sections'] ?? [];
        $allSectionKeys = array_unique(array_merge(array_keys($prevSections), array_keys($currSections)));
        foreach ($allSectionKeys as $key) {
            $prevSectionScore = (int) ($prevSections[$key]['score'] ?? 0);
            $currSectionScore = (int) ($currSections[$key]['score'] ?? 0);
            $sectionDeltas[$key] = $currSectionScore - $prevSectionScore;
        }

        $prevRisks = $previousCoherence['risks'] ?? [];
        $currRisks = $currentCoherence['risks'] ?? [];
        $prevRiskIds = $this->riskIds($prevRisks);
        $currRiskIds = $this->riskIds($currRisks);
        $resolvedRisks = array_values(array_filter($prevRisks, fn ($r) => ! in_array($this->riskId($r), $currRiskIds)));
        $newRisks = array_values(array_filter($currRisks, fn ($r) => ! in_array($this->riskId($r), $prevRiskIds)));

        return [
            'overall_delta' => $overallDelta,
            'section_deltas' => $sectionDeltas,
            'resolved_risks' => $resolvedRisks,
            'new_risks' => $newRisks,
        ];
    }

    protected function riskIds(array $risks): array
    {
        return array_map([$this, 'riskId'], $risks);
    }

    protected function riskId(mixed $risk): string
    {
        if (is_array($risk) && isset($risk['id'])) {
            return (string) $risk['id'];
        }

        return '';
    }
}
