<?php

namespace Tests\Unit\Services\BrandIntelligence;

use App\Models\Asset;
use App\Services\BrandIntelligence\BrandIntelligenceTextEvidence;
use Tests\TestCase;

class BrandIntelligenceTextEvidenceTest extends TestCase
{
    public function test_native_pdf_text_before_metadata_and_skips_duplicate_supplemental(): void
    {
        $asset = new Asset([
            'id' => 999001,
            'metadata' => [
                'ocr_text' => 'Shared line',
                'extracted_text' => 'Shared line',
            ],
        ]);

        $segments = BrandIntelligenceTextEvidence::orderedTextSegments($asset, 'Shared line');
        $this->assertSame(['Shared line'], $segments);
    }

    public function test_metadata_then_supplemental_when_distinct(): void
    {
        $asset = new Asset([
            'id' => 999002,
            'metadata' => [
                'extracted_text' => 'From metadata',
            ],
        ]);

        $segments = BrandIntelligenceTextEvidence::orderedTextSegments($asset, 'From vision OCR only');
        $this->assertSame(['From metadata', 'From vision OCR only'], $segments);
    }

    public function test_merged_copy_voice_raw_joins_segments(): void
    {
        $asset = new Asset([
            'id' => 999003,
            'metadata' => ['ocr_text' => 'Line one'],
        ]);

        $raw = BrandIntelligenceTextEvidence::mergedCopyVoiceRaw($asset, 'Line two');
        $this->assertStringContainsString('Line one', $raw);
        $this->assertStringContainsString('Line two', $raw);
    }
}
