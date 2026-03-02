<?php

namespace Tests\Unit\Services\BrandDNA\Extraction;

use App\Services\BrandDNA\Extraction\BrandGuidelinesProcessor;
use PHPUnit\Framework\TestCase;

class BrandGuidelinesProcessorTest extends TestCase
{
    public function test_pdf_processor_detects_explicit_archetype(): void
    {
        $processor = new BrandGuidelinesProcessor;
        $text = "Brand Guidelines\n\nBrand Archetype: Creator\n\nOur brand is innovative.";
        $result = $processor->process($text);

        $this->assertSame('Creator', $result['personality']['primary_archetype']);
        $this->assertTrue($result['explicit_signals']['archetype_declared']);
    }

    public function test_pdf_processor_extracts_hex_colors(): void
    {
        $processor = new BrandGuidelinesProcessor;
        $text = "Brand Colors\nPrimary: #003388\nSecondary: #FF6600\nAccent: #00AA00";
        $result = $processor->process($text);

        $colors = $result['visual']['primary_colors'];
        $this->assertNotEmpty($colors);
        $this->assertContains('#003388', $colors);
        $this->assertContains('#FF6600', $colors);
        $this->assertContains('#00AA00', $colors);
    }

    public function test_pdf_processor_extracts_mission_and_sets_explicit(): void
    {
        $processor = new BrandGuidelinesProcessor;
        $text = "Our Mission\nTo empower creators worldwide.";
        $result = $processor->process($text);

        $this->assertStringContainsString('empower', $result['identity']['mission'] ?? '');
        $this->assertTrue($result['explicit_signals']['mission_declared']);
    }

    public function test_archetype_inferred_without_explicit_heading(): void
    {
        $processor = new BrandGuidelinesProcessor;
        $text = "We are a Creator brand focused on innovation.";
        $result = $processor->process($text);

        $this->assertSame('Creator', $result['personality']['primary_archetype']);
        $this->assertFalse($result['explicit_signals']['archetype_declared']);
    }
}
