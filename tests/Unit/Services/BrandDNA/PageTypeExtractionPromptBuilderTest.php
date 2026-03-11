<?php

namespace Tests\Unit\Services\BrandDNA;

use App\Services\BrandDNA\PageTypeExtractionPromptBuilder;
use Tests\TestCase;

class PageTypeExtractionPromptBuilderTest extends TestCase
{
    public function test_archetype_page_prompt_includes_archetype_fields(): void
    {
        $builder = new PageTypeExtractionPromptBuilder();
        $prompt = $builder->buildPrompt('archetype');

        $this->assertStringContainsString('archetype', $prompt);
        $this->assertStringContainsString('tone', $prompt);
    }

    public function test_color_palette_page_prompt_includes_color_fields(): void
    {
        $builder = new PageTypeExtractionPromptBuilder();
        $prompt = $builder->buildPrompt('color_palette');

        $this->assertStringContainsString('color', $prompt);
        $this->assertStringContainsString('hex', $prompt);
    }

    public function test_typography_page_prompt_includes_font_fields(): void
    {
        $builder = new PageTypeExtractionPromptBuilder();
        $prompt = $builder->buildPrompt('typography');

        $this->assertStringContainsString('font', $prompt);
        $this->assertStringContainsString('heading', $prompt);
    }

    public function test_example_gallery_prompt_does_not_include_mission_or_positioning_as_targets(): void
    {
        $builder = new PageTypeExtractionPromptBuilder();
        $prompt = $builder->buildPrompt('example_gallery');

        $this->assertStringContainsString('photography', $prompt);
        $this->assertStringContainsString('visual', $prompt);
        $this->assertStringNotContainsString('- mission', $prompt);
        $this->assertStringNotContainsString('- positioning', $prompt);
    }

    public function test_table_of_contents_returns_empty_targets(): void
    {
        $builder = new PageTypeExtractionPromptBuilder();
        $prompt = $builder->buildPrompt('table_of_contents');

        $this->assertStringContainsString('none (extract nothing)', $prompt);
    }

    public function test_allowed_fields_from_config(): void
    {
        config(['brand_dna_page_extraction.allowed_fields_by_page_type' => [
            'color_palette' => ['visual.primary_colors', 'scoring_rules.allowed_color_palette'],
        ]]);

        $builder = new PageTypeExtractionPromptBuilder();
        $fields = $builder->getExpectedFieldsForPageType('color_palette');

        $this->assertContains('visual.primary_colors', $fields);
        $this->assertContains('scoring_rules.allowed_color_palette', $fields);
    }
}
