<?php

namespace Tests\Unit\Services\BrandDNA;

use App\Services\BrandDNA\BrandGuidelineSectionParser;
use PHPUnit\Framework\TestCase;

class BrandGuidelineSectionParserTest extends TestCase
{
    public function test_suppresses_repeated_address_footer(): void
    {
        $text = "BRAND VOICE\nOur voice is bold.\n\n" .
            str_repeat("VERSA GRIPPS • 571 US HWY 1 • HANCOCK, ME 04640, USA\n\n", 5) .
            "BRAND POSITIONING\nWe lead the category.";
        $result = BrandGuidelineSectionParser::parse($text);

        $this->assertNotEmpty($result['suppressed_lines'] ?? []);
        $this->assertContains('VERSA GRIPPS • 571 US HWY 1 • HANCOCK, ME 04640, USA', $result['suppressed_lines']);
        $sections = $result['sections'];
        $titles = array_column($sections, 'title');
        $this->assertNotContains('VERSA GRIPPS • 571 US HWY 1 • HANCOCK, ME 04640, USA', $titles);
    }

    public function test_suppresses_address_like_patterns(): void
    {
        $text = "CONTACT\n123 Main St, USA 12345\n\n" .
            str_repeat("Company • 100 HWY 1 • Boston, MA 02101, USA\n\n", 4);
        $result = BrandGuidelineSectionParser::parse($text);

        $suppressed = $result['suppressed_lines'] ?? [];
        $this->assertNotEmpty($suppressed);
    }

    public function test_toc_parsing_dotted_leaders(): void
    {
        $text = "TABLE OF CONTENTS\n\n" .
            "BRAND ARCHETYPE .............. 5\n" .
            "BRAND VOICE .................. 12\n" .
            "COLOR PALETTE ................. 24\n";
        $result = BrandGuidelineSectionParser::parse($text);

        $toc = $result['toc_map'];
        $this->assertSame(5, $toc['BRAND ARCHETYPE'] ?? null);
        $this->assertSame(12, $toc['BRAND VOICE'] ?? null);
        $this->assertSame(24, $toc['COLOR PALETTE'] ?? null);
    }

    public function test_toc_parsing_spaced_titles(): void
    {
        $text = "CONTENTS\n\n" .
            "Brand Archetype  5\n" .
            "Brand Voice  12\n";
        $result = BrandGuidelineSectionParser::parse($text);

        $toc = $result['toc_map'];
        $this->assertSame(5, $toc['BRAND ARCHETYPE'] ?? null);
        $this->assertSame(12, $toc['BRAND VOICE'] ?? null);
    }

    public function test_sections_have_source_and_confidence(): void
    {
        $text = "TABLE OF CONTENTS\n\nBRAND VOICE .............. 3\n\n" .
            "BRAND VOICE\nOur voice is authentic and bold.";
        $result = BrandGuidelineSectionParser::parse($text);

        $this->assertNotEmpty($result['sections']);
        $section = $result['sections'][0];
        $this->assertArrayHasKey('source', $section);
        $this->assertArrayHasKey('confidence', $section);
        $this->assertContains($section['source'], ['toc', 'heuristic']);
    }

    public function test_preserves_valid_known_sections_after_suppression(): void
    {
        $footer = "VERSA GRIPPS • 571 US HWY 1 • HANCOCK, ME 04640, USA";
        $text = "BRAND ARCHETYPE\nWe are the Hero archetype.\n\n" .
            str_repeat("$footer\n\n", 4) .
            "BRAND VOICE\nBold and authentic.\n\n" .
            str_repeat("$footer\n\n", 4);
        $result = BrandGuidelineSectionParser::parse($text);

        $this->assertNotEmpty($result['sections']);
        $titles = array_map(fn ($s) => strtoupper($s['title'] ?? ''), $result['sections']);
        $this->assertTrue(
            in_array('BRAND ARCHETYPE', $titles) || in_array('BRAND VOICE', $titles),
            'Expected at least one of BRAND ARCHETYPE or BRAND VOICE in sections: ' . json_encode($titles)
        );
        $content = implode(' ', array_column($result['sections'], 'content'));
        $this->assertTrue(
            str_contains($content, 'Hero') || str_contains($content, 'authentic'),
            'Expected Hero or authentic in section content. Got: ' . substr($content, 0, 100)
        );
    }

    public function test_toc_backed_section_gets_higher_quality_score(): void
    {
        $text = "TABLE OF CONTENTS\n\nBRAND VOICE .............. 3\n\n" .
            "BRAND VOICE\nOur voice is authentic, bold, and distinctive. We speak with confidence.";
        $result = BrandGuidelineSectionParser::parse($text);

        $this->assertNotEmpty($result['sections']);
        $section = $result['sections'][0];
        $this->assertArrayHasKey('quality_score', $section);
        $this->assertArrayHasKey('content_length', $section);
        $this->assertSame('toc', $section['source']);
        $this->assertGreaterThanOrEqual(0.75, $section['quality_score']);
    }

    public function test_sections_have_quality_score_and_content_length(): void
    {
        $result = BrandGuidelineSectionParser::parse(
            "BRAND VOICE\nOur voice is bold and authentic.\n\n" .
            "BRAND POSITIONING\nWe lead the category with excellence."
        );

        $this->assertNotEmpty($result['sections']);
        foreach ($result['sections'] as $section) {
            $this->assertArrayHasKey('quality_score', $section);
            $this->assertArrayHasKey('content_length', $section);
            $this->assertGreaterThanOrEqual(0.0, $section['quality_score']);
            $this->assertLessThanOrEqual(1.0, $section['quality_score']);
        }
    }

    public function test_repeated_weak_heuristic_headings_are_suppressed(): void
    {
        $chunk = "EXAMPLE SECTION\nab\n\n";
        $text = str_repeat($chunk, 5);
        $result = BrandGuidelineSectionParser::parse($text);

        $titles = array_column($result['sections'], 'title');
        $this->assertNotContains('EXAMPLE SECTION', $titles);
        $this->assertNotEmpty($result['suppressed_sections'] ?? []);
        $suppressed = $result['suppressed_sections'];
        $exampleSection = collect($suppressed)->firstWhere('title', 'EXAMPLE SECTION');
        $this->assertNotNull($exampleSection);
        $this->assertSame('repeated_weak_heuristic_heading', $exampleSection['reason']);
    }

    public function test_repeated_toc_backed_headings_are_not_suppressed(): void
    {
        $text = "TABLE OF CONTENTS\n\nBRAND VOICE .............. 2\n\n" .
            "BRAND VOICE\nOur voice is bold and authentic.\n\n" .
            "BRAND VOICE\nOur voice is bold and authentic.";
        $result = BrandGuidelineSectionParser::parse($text);

        $titles = array_map(fn ($s) => strtoupper($s['title'] ?? ''), $result['sections']);
        $this->assertContains('BRAND VOICE', $titles);
    }

    public function test_protected_real_sections_are_not_suppressed(): void
    {
        $text = "BRAND VOICE\nOur voice is bold and authentic with sufficient content.\n\n" .
            "BRAND POSITIONING\nWe lead the category with excellence.";
        $result = BrandGuidelineSectionParser::parse($text);

        $titles = array_map(fn ($s) => strtoupper($s['title'] ?? ''), $result['sections']);
        $this->assertTrue(
            in_array('BRAND VOICE', $titles) || in_array('BRAND POSITIONING', $titles),
            'Expected BRAND VOICE or BRAND POSITIONING in sections: ' . json_encode($titles)
        );
    }

    public function test_repeated_weak_headings_collapsed_into_synthetic_debug_entry(): void
    {
        $chunk = "EXAMPLE SECTION\nab\n\n";
        $text = str_repeat($chunk, 5);
        $result = BrandGuidelineSectionParser::parse($text);

        $this->assertNotEmpty($result['collapsed_sections'] ?? []);
        $collapsed = $result['collapsed_sections'];
        $entry = collect($collapsed)->firstWhere('title', 'EXAMPLE SECTION');
        $this->assertNotNull($entry);
        $this->assertSame('collapsed_repeated_heading', $entry['source']);
        $this->assertSame(5, $entry['occurrences']);
        $this->assertFalse($entry['used_for_extraction']);
    }

    public function test_section_counts_reflect_suppression(): void
    {
        $chunk = "EXAMPLE SECTION\nab\n\n";
        $text = str_repeat($chunk, 5);
        $result = BrandGuidelineSectionParser::parse($text);

        $this->assertArrayHasKey('section_count_raw', $result);
        $this->assertArrayHasKey('section_count_suppressed', $result);
        $this->assertSame(5, $result['section_count_raw']);
        $this->assertSame(5, $result['section_count_suppressed']);
        $this->assertCount(0, $result['sections']);
    }
}
