<?php

namespace Tests\Unit\Services\BrandDNA;

use App\Services\BrandDNA\BrandCoherenceScoringService;
use PHPUnit\Framework\TestCase;

/**
 * Brand Coherence Scoring Service — unit tests for snapshot influence.
 */
class BrandCoherenceScoringServiceTest extends TestCase
{
    public function test_coherence_changes_when_snapshot_colors_match(): void
    {
        $service = new BrandCoherenceScoringService;
        $draftPayload = [
            'scoring_rules' => ['allowed_color_palette' => ['#000000']],
        ];

        $resultEmpty = $service->score($draftPayload, [], null, null, 0);
        $standardsEmpty = $resultEmpty['sections']['standards']['score'] ?? 0;

        $snapshotWithMatch = ['primary_colors' => ['#000000']];
        $resultMatch = $service->score($draftPayload, [], $snapshotWithMatch, null, 0);
        $standardsMatch = $resultMatch['sections']['standards']['score'] ?? 0;

        $this->assertGreaterThan($standardsEmpty, $standardsMatch, 'Standards score must be higher when snapshot colors match draft palette');
    }
}
