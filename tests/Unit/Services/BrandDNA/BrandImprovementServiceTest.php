<?php

namespace Tests\Unit\Services\BrandDNA;

use App\Services\BrandDNA\BrandImprovementService;
use PHPUnit\Framework\TestCase;

class BrandImprovementServiceTest extends TestCase
{
    public function test_improve_my_score_targets_lowest_section(): void
    {
        $service = new BrandImprovementService;

        $draftPayload = [
            'identity' => ['mission' => 'We empower.', 'positioning' => 'Leading platform.'],
            'personality' => ['primary_archetype' => 'Creator'],
            'scoring_rules' => ['tone_keywords' => ['bold'], 'allowed_color_palette' => [['hex' => '#003388']]],
            'typography' => ['primary_font' => 'Inter'],
        ];

        $coherence = [
            'sections' => [
                'background' => ['score' => 80, 'notes' => ['Background sources present']],
                'archetype' => ['score' => 90, 'notes' => ['Archetype selection complete']],
                'purpose' => ['score' => 85, 'notes' => ['Mission defined', 'Positioning defined']],
                'expression' => ['score' => 30, 'notes' => ['Add Brand Look, Brand Voice, tone keywords, or traits']],
                'positioning' => ['score' => 70, 'notes' => ['Add more positioning fields']],
                'standards' => ['score' => 75, 'notes' => ['Standards defined']],
            ],
        ];

        $result = $service->suggestImprovements($draftPayload, $coherence);

        $this->assertArrayHasKey('suggestions', $result);
        $this->assertArrayHasKey('lowest_section', $result);
        $this->assertSame('expression', $result['lowest_section']);
        $this->assertNotEmpty($result['suggestions']);
        $suggestion = $result['suggestions'][0] ?? null;
        $this->assertNotNull($suggestion);
        $this->assertSame('SUG:improve.expression', $suggestion['key']);
    }

    public function test_improve_my_score_empty_sections_returns_empty(): void
    {
        $service = new BrandImprovementService;

        $result = $service->suggestImprovements([], []);

        $this->assertEmpty($result['suggestions']);
        $this->assertNull($result['lowest_section']);
    }
}
