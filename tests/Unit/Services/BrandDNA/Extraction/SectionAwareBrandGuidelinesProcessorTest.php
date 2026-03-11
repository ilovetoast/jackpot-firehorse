<?php

namespace Tests\Unit\Services\BrandDNA\Extraction;

use App\Services\BrandDNA\Extraction\SectionAwareBrandGuidelinesProcessor;
use PHPUnit\Framework\TestCase;

class SectionAwareBrandGuidelinesProcessorTest extends TestCase
{
    public function test_rejects_low_quality_positioning_fragment(): void
    {
        $processor = new SectionAwareBrandGuidelinesProcessor;
        $text = "BRAND POSITIONING\nCONSUMER        within a category.";
        $result = $processor->process($text);

        $this->assertNull($result['identity']['positioning'] ?? null, 'Low-quality fragment must not be set as positioning');
    }

    public function test_preserves_valid_positioning(): void
    {
        $processor = new SectionAwareBrandGuidelinesProcessor;
        $text = "BRAND POSITIONING\nVersa Gripps is the leading grip-enhancing brand for fitness professionals worldwide.";
        $result = $processor->process($text);

        $this->assertNotNull($result['identity']['positioning']);
        $this->assertStringContainsString('Versa Gripps', $result['identity']['positioning']);
    }

    public function test_falls_back_when_no_sections(): void
    {
        $processor = new SectionAwareBrandGuidelinesProcessor;
        $text = "Random text with no clear sections. Just a paragraph.";
        $result = $processor->process($text);

        $this->assertEmpty($result['sections'] ?? []);
    }

    public function test_is_trusted_section(): void
    {
        $this->assertTrue(SectionAwareBrandGuidelinesProcessor::isTrustedSection('BRAND ARCHETYPE'));
        $this->assertTrue(SectionAwareBrandGuidelinesProcessor::isTrustedSection('Brand Voice'));
        $this->assertTrue(SectionAwareBrandGuidelinesProcessor::isTrustedSection('TYPOGRAPHY'));
        $this->assertFalse(SectionAwareBrandGuidelinesProcessor::isTrustedSection('RANDOM SECTION'));
    }

    public function test_strongest_relevant_section_chosen_when_multiple_candidates(): void
    {
        $processor = new SectionAwareBrandGuidelinesProcessor;
        $text = "TABLE OF CONTENTS\n\nPROMISE .............. 3\n\n" .
            "BRAND POSITIONING\nShort fragment.\n\n" .
            "PROMISE\nVersa Gripps is the leading grip-enhancing brand for fitness professionals worldwide.";
        $result = $processor->process($text);

        $this->assertNotNull($result['identity']['positioning']);
        $this->assertStringContainsString('Versa Gripps', $result['identity']['positioning']);
        $this->assertStringContainsString('PROMISE', $result['section_sources']['identity.positioning'] ?? '');
    }

    public function test_low_quality_sections_do_not_populate(): void
    {
        $processor = new SectionAwareBrandGuidelinesProcessor;
        $text = "BRAND POSITIONING\n" . str_repeat('x', 31) . "\n\n";
        $result = $processor->process($text);

        $this->assertNull($result['identity']['positioning'] ?? null);
    }
}
