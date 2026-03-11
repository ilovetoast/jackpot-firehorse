<?php

namespace Tests\Unit\Services\BrandDNA;

use App\Services\BrandDNA\ArchetypeExplicitDetectionService;
use Tests\TestCase;

class ArchetypeExplicitDetectionServiceTest extends TestCase
{
    protected ArchetypeExplicitDetectionService $detector;

    protected function setUp(): void
    {
        parent::setUp();
        config(['brand_dna_archetypes.allowlist' => [
            'hero', 'sage', 'explorer', 'ruler', 'creator', 'caregiver',
            'everyman', 'magician', 'lover', 'jester', 'innocent', 'outlaw',
        ]]);
        config(['brand_dna_archetypes.display_map' => [
            'ruler' => 'Ruler',
            'hero' => 'Hero',
        ] + array_combine(
            ['sage', 'explorer', 'creator', 'caregiver', 'everyman', 'magician', 'lover', 'jester', 'innocent', 'outlaw'],
            ['Sage', 'Explorer', 'Creator', 'Caregiver', 'Everyman', 'Magician', 'Lover', 'Jester', 'Innocent', 'Outlaw']
        )]);
        $this->detector = new ArchetypeExplicitDetectionService;
    }

    public function test_explicit_detection_of_ruler_from_a_ruler_for_the_people(): void
    {
        $result = $this->detector->detect(
            "BRAND ARCHETYPE\n\nA RULER FOR THE PEOPLE\n\nRULER ATTRIBUTES",
            null,
            []
        );

        $this->assertTrue($result['matched']);
        $this->assertSame('Ruler', $result['value']);
        $this->assertGreaterThanOrEqual(0.85, $result['confidence']);
    }

    public function test_detection_from_ruler_attributes(): void
    {
        $result = $this->detector->detect(
            "RULER ATTRIBUTES\n\nLeadership\nAuthority",
            null,
            []
        );

        $this->assertTrue($result['matched']);
        $this->assertSame('Ruler', $result['value']);
    }

    public function test_exact_match_normalization_from_uppercase_to_display_case(): void
    {
        $result = $this->detector->detect('RULER', null, []);

        $this->assertTrue($result['matched']);
        $this->assertSame('Ruler', $result['value']);
    }

    public function test_no_false_match_when_archetype_name_absent(): void
    {
        $result = $this->detector->detect(
            "BRAND GUIDELINES\n\nTypography and colors.",
            null,
            []
        );

        $this->assertFalse($result['matched']);
    }

    public function test_detection_from_page_title(): void
    {
        $result = $this->detector->detect(
            null,
            'A RULER FOR THE PEOPLE',
            ['title' => 'Brand Archetype']
        );

        $this->assertTrue($result['matched']);
        $this->assertSame('Ruler', $result['value']);
    }

    public function test_evidence_included(): void
    {
        $result = $this->detector->detect(
            "A RULER FOR THE PEOPLE\n\nRULER ATTRIBUTES",
            null,
            []
        );

        $this->assertTrue($result['matched']);
        $this->assertNotEmpty($result['evidence']);
    }
}
