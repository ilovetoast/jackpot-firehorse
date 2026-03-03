<?php

namespace Tests\Unit\Services\BrandDNA\Extraction;

use App\Services\BrandDNA\Extraction\ConflictDetectionService;
use PHPUnit\Framework\TestCase;

class ConflictDetectionServiceTest extends TestCase
{
    public function test_conflict_detected_when_values_differ(): void
    {
        $service = new ConflictDetectionService;
        $ext1 = [
            'personality' => ['primary_archetype' => 'Ruler'],
            'sources' => ['pdf' => ['extracted' => true]],
            'explicit_signals' => ['archetype_declared' => true],
        ];
        $ext2 = [
            'personality' => ['primary_archetype' => 'Hero'],
            'sources' => ['website' => ['hero_headlines' => []]],
            'explicit_signals' => ['archetype_declared' => false],
        ];

        $conflicts = $service->detect([$ext1, $ext2]);

        $this->assertNotEmpty($conflicts);
        $arch = collect($conflicts)->firstWhere('field', 'personality.primary_archetype');
        $this->assertNotNull($arch);
        $this->assertSame('Ruler', $arch['recommended']);
        $this->assertGreaterThan(0.6, $arch['recommended_weight']);
    }

    public function test_null_colors_do_not_generate_conflict(): void
    {
        $service = new ConflictDetectionService;
        $ext1 = [
            'identity' => ['mission' => null, 'vision' => null],
            'personality' => ['primary_archetype' => null],
            'sources' => ['pdf' => ['extracted' => true]],
            'explicit_signals' => [],
        ];
        $ext2 = [
            'identity' => ['mission' => null, 'vision' => null],
            'personality' => ['primary_archetype' => null],
            'sources' => ['website' => []],
            'explicit_signals' => [],
        ];

        $conflicts = $service->detect([$ext1, $ext2]);

        $this->assertEmpty($conflicts, 'Null values must not generate conflicts');
    }
}
