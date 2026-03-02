<?php

namespace Tests\Unit\Services\BrandDNA;

use App\Services\BrandDNA\CoherenceDeltaService;
use PHPUnit\Framework\TestCase;

class CoherenceDeltaServiceTest extends TestCase
{
    protected CoherenceDeltaService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CoherenceDeltaService;
    }

    public function test_positive_delta(): void
    {
        $previous = [
            'overall' => ['score' => 60, 'coverage' => 50, 'confidence' => 70],
            'sections' => [
                'background' => ['score' => 70],
                'archetype' => ['score' => 50],
            ],
            'risks' => [],
        ];
        $current = [
            'overall' => ['score' => 75, 'coverage' => 60, 'confidence' => 80],
            'sections' => [
                'background' => ['score' => 75],
                'archetype' => ['score' => 55],
            ],
            'risks' => [],
        ];

        $result = $this->service->calculate($previous, $current);

        $this->assertSame(15, $result['overall_delta']);
        $this->assertSame(5, $result['section_deltas']['background']);
        $this->assertSame(5, $result['section_deltas']['archetype']);
    }

    public function test_negative_delta(): void
    {
        $previous = [
            'overall' => ['score' => 80],
            'sections' => ['standards' => ['score' => 85]],
            'risks' => [],
        ];
        $current = [
            'overall' => ['score' => 70],
            'sections' => ['standards' => ['score' => 75]],
            'risks' => [],
        ];

        $result = $this->service->calculate($previous, $current);

        $this->assertSame(-10, $result['overall_delta']);
        $this->assertSame(-10, $result['section_deltas']['standards']);
    }

    public function test_resolved_risks_detected(): void
    {
        $previous = [
            'overall' => ['score' => 50],
            'sections' => [],
            'risks' => [
                ['id' => 'COH:WEAK_STANDARDS', 'label' => 'Standards incomplete', 'detail' => 'Add colors.'],
                ['id' => 'COH:COLOR_MISMATCH', 'label' => 'Color mismatch', 'detail' => 'Website colors differ.'],
            ],
        ];
        $current = [
            'overall' => ['score' => 70],
            'sections' => [],
            'risks' => [
                ['id' => 'COH:COLOR_MISMATCH', 'label' => 'Color mismatch', 'detail' => 'Website colors differ.'],
            ],
        ];

        $result = $this->service->calculate($previous, $current);

        $this->assertCount(1, $result['resolved_risks']);
        $this->assertSame('COH:WEAK_STANDARDS', $result['resolved_risks'][0]['id']);
    }

    public function test_new_risks_detected(): void
    {
        $previous = [
            'overall' => ['score' => 70],
            'sections' => [],
            'risks' => [
                ['id' => 'COH:COLOR_MISMATCH', 'label' => 'Color mismatch', 'detail' => 'Website colors differ.'],
            ],
        ];
        $current = [
            'overall' => ['score' => 60],
            'sections' => [],
            'risks' => [
                ['id' => 'COH:COLOR_MISMATCH', 'label' => 'Color mismatch', 'detail' => 'Website colors differ.'],
                ['id' => 'COH:FONT_MISMATCH', 'label' => 'Font mismatch', 'detail' => 'Detected fonts differ.'],
            ],
        ];

        $result = $this->service->calculate($previous, $current);

        $this->assertCount(1, $result['new_risks']);
        $this->assertSame('COH:FONT_MISMATCH', $result['new_risks'][0]['id']);
    }

    public function test_does_not_mutate_inputs(): void
    {
        $previous = [
            'overall' => ['score' => 60],
            'sections' => ['background' => ['score' => 70]],
            'risks' => [['id' => 'R1', 'label' => 'Risk 1']],
        ];
        $current = [
            'overall' => ['score' => 65],
            'sections' => ['background' => ['score' => 75]],
            'risks' => [],
        ];
        $previousCopy = json_decode(json_encode($previous), true);
        $currentCopy = json_decode(json_encode($current), true);

        $this->service->calculate($previous, $current);

        $this->assertSame($previousCopy, $previous);
        $this->assertSame($currentCopy, $current);
    }
}
