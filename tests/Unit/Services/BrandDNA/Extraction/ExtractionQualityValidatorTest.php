<?php

namespace Tests\Unit\Services\BrandDNA\Extraction;

use App\Services\BrandDNA\Extraction\ExtractionQualityValidator;
use PHPUnit\Framework\TestCase;

class ExtractionQualityValidatorTest extends TestCase
{
    public function test_rejects_null_or_empty(): void
    {
        $this->assertTrue(ExtractionQualityValidator::isLowQualityExtractedValue(null));
        $this->assertTrue(ExtractionQualityValidator::isLowQualityExtractedValue(''));
        $this->assertTrue(ExtractionQualityValidator::isLowQualityExtractedValue('   '));
    }

    public function test_rejects_short_values(): void
    {
        $this->assertTrue(ExtractionQualityValidator::isLowQualityExtractedValue('short'));
        $this->assertTrue(ExtractionQualityValidator::isLowQualityExtractedValue('BRAND'));
        $this->assertTrue(ExtractionQualityValidator::isLowQualityExtractedValue('N/A'));
    }

    public function test_rejects_positioning_fragment(): void
    {
        $this->assertTrue(ExtractionQualityValidator::isLowQualityExtractedValue('CONSUMER        within a category.'));
        $this->assertTrue(ExtractionQualityValidator::isLowQualityExtractedValue('within a category'));
        $this->assertTrue(ExtractionQualityValidator::isLowQualityExtractedValue('CONSUMER within a category.'));
    }

    public function test_rejects_generic_placeholders(): void
    {
        $this->assertTrue(ExtractionQualityValidator::isLowQualityExtractedValue('TBD'));
        $this->assertTrue(ExtractionQualityValidator::isLowQualityExtractedValue('placeholder'));
        $this->assertTrue(ExtractionQualityValidator::isLowQualityExtractedValue('example text'));
    }

    public function test_accepts_valid_positioning(): void
    {
        $this->assertFalse(ExtractionQualityValidator::isLowQualityExtractedValue(
            'Versa Gripps is the leading brand in grip-enhancing products for fitness professionals.'
        ));
        $this->assertFalse(ExtractionQualityValidator::isLowQualityExtractedValue(
            'We empower athletes to perform at their peak with innovative grip technology.'
        ));
    }

    public function test_accepts_valid_mission(): void
    {
        $this->assertFalse(ExtractionQualityValidator::isLowQualityExtractedValue(
            'To create the most trusted grip solutions for strength athletes worldwide.'
        ));
    }
}
