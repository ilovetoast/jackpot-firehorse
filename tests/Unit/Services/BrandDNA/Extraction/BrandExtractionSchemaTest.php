<?php

namespace Tests\Unit\Services\BrandDNA\Extraction;

use App\Services\BrandDNA\Extraction\BrandExtractionSchema;
use PHPUnit\Framework\TestCase;

class BrandExtractionSchemaTest extends TestCase
{
    public function test_higher_weight_wins_in_merge(): void
    {
        $ext1 = BrandExtractionSchema::empty();
        $ext1['personality']['primary_archetype'] = 'Hero';
        $ext1['sources']['website'] = ['hero_headlines' => []];
        $ext1['explicit_signals'] = ['archetype_declared' => false];

        $ext2 = BrandExtractionSchema::empty();
        $ext2['personality']['primary_archetype'] = 'Ruler';
        $ext2['sources']['pdf'] = ['extracted' => true];
        $ext2['explicit_signals'] = ['archetype_declared' => true];

        $result = BrandExtractionSchema::merge($ext1, $ext2);

        $this->assertSame('Ruler', $result['personality']['primary_archetype']);
    }
}
