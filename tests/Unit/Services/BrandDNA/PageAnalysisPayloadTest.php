<?php

namespace Tests\Unit\Services\BrandDNA;

use Tests\TestCase;

class PageAnalysisPayloadTest extends TestCase
{
    public function test_accepted_rejected_and_merge_contributions_tracked(): void
    {
        $pageAnalysis = [
            [
                'page' => 5,
                'page_type' => 'positioning',
                'accepted_candidates' => [
                    ['path' => 'identity.positioning', 'value' => 'Leading brand in premium fitness.'],
                ],
                'rejected_candidates' => [
                    ['path' => 'identity.mission', 'value' => 'within a category', 'reason' => 'fragmentary_narrative'],
                ],
                'used_in_final_merge' => ['identity.positioning'],
            ],
        ];

        $this->assertCount(1, $pageAnalysis[0]['accepted_candidates']);
        $this->assertCount(1, $pageAnalysis[0]['rejected_candidates']);
        $this->assertSame('fragmentary_narrative', $pageAnalysis[0]['rejected_candidates'][0]['reason']);
        $this->assertContains('identity.positioning', $pageAnalysis[0]['used_in_final_merge']);
    }

    public function test_page_analysis_includes_required_fields(): void
    {
        $page = [
            'page' => 11,
            'thumbnail_url' => 'data:image/webp;base64,...',
            'page_type' => 'archetype',
            'classification_confidence' => 0.96,
            'page_title' => 'Brand Archetype',
            'ocr_text_excerpt' => 'BRAND ARCHETYPE ... A RULER FOR THE PEOPLE ...',
            'raw_candidates' => [['path' => 'personality.primary_archetype', 'value' => 'Ruler']],
            'accepted_candidates' => [['path' => 'personality.primary_archetype', 'value' => 'Ruler']],
            'rejected_candidates' => [
                ['path' => 'identity.positioning', 'value' => 'within a category', 'reason' => 'fragmentary_narrative'],
            ],
            'used_in_final_merge' => ['personality.primary_archetype'],
        ];

        $this->assertArrayHasKey('page', $page);
        $this->assertArrayHasKey('page_type', $page);
        $this->assertArrayHasKey('classification_confidence', $page);
        $this->assertArrayHasKey('raw_candidates', $page);
        $this->assertArrayHasKey('accepted_candidates', $page);
        $this->assertArrayHasKey('rejected_candidates', $page);
        $this->assertArrayHasKey('used_in_final_merge', $page);
        $this->assertSame('fragmentary_narrative', $page['rejected_candidates'][0]['reason']);
    }
}
