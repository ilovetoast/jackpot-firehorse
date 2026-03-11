<?php

namespace Tests\Unit\Services\BrandDNA;

use App\Services\BrandDNA\FieldCandidateValidationService;
use Tests\TestCase;

class FieldCandidateValidationServiceTypographyTest extends TestCase
{
    protected FieldCandidateValidationService $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new FieldCandidateValidationService;
    }

    public function test_rejects_and_prominent_vg_ligature(): void
    {
        $result = $this->validator->validate([
            'path' => 'typography.primary_font',
            'value' => 'And Prominent Vg Ligature',
            'confidence' => 0.8,
        ]);

        $this->assertFalse($result['accepted']);
    }

    public function test_accepts_helvetica_neue(): void
    {
        $result = $this->validator->validate([
            'path' => 'typography.primary_font',
            'value' => 'Helvetica Neue',
            'confidence' => 0.9,
        ]);

        $this->assertTrue($result['accepted']);
    }

    public function test_accepts_trade_gothic(): void
    {
        $result = $this->validator->validate([
            'path' => 'typography.primary_font',
            'value' => 'Trade Gothic',
            'confidence' => 0.9,
        ]);

        $this->assertTrue($result['accepted']);
    }

    public function test_rejects_long_multiline_fragments(): void
    {
        $result = $this->validator->validate([
            'path' => 'typography.primary_font',
            'value' => "Line one\nLine two\nLine three",
            'confidence' => 0.8,
        ]);

        $this->assertFalse($result['accepted']);
    }
}
