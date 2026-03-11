<?php

namespace Tests\Unit\Services\BrandDNA;

use Tests\TestCase;

/**
 * Page type config routing for narrative fields.
 */
class PageTypeRoutingTest extends TestCase
{
    public function test_positioning_page_type_routes_to_identity_positioning(): void
    {
        $config = config('brand_dna_page_extraction.allowed_fields_by_page_type', []);
        $this->assertArrayHasKey('positioning', $config);
        $this->assertContains('identity.positioning', $config['positioning']);
    }

    public function test_purpose_page_type_routes_to_identity_mission(): void
    {
        $config = config('brand_dna_page_extraction.allowed_fields_by_page_type', []);
        $this->assertArrayHasKey('purpose', $config);
        $this->assertContains('identity.mission', $config['purpose']);
    }

    public function test_brand_voice_page_type_routes_to_personality_tone_keywords(): void
    {
        $config = config('brand_dna_page_extraction.allowed_fields_by_page_type', []);
        $this->assertArrayHasKey('brand_voice', $config);
        $this->assertContains('personality.tone_keywords', $config['brand_voice']);
    }

    public function test_strategy_page_type_routes_to_narrative_fields(): void
    {
        $config = config('brand_dna_page_extraction.allowed_fields_by_page_type', []);
        $this->assertArrayHasKey('strategy', $config);
        $this->assertContains('identity.mission', $config['strategy']);
        $this->assertContains('identity.positioning', $config['strategy']);
    }

    public function test_promise_page_type_routes_to_identity_positioning(): void
    {
        $config = config('brand_dna_page_extraction.allowed_fields_by_page_type', []);
        $this->assertArrayHasKey('promise', $config);
        $this->assertContains('identity.positioning', $config['promise']);
    }

    public function test_brand_story_page_type_routes_to_mission_and_positioning(): void
    {
        $config = config('brand_dna_page_extraction.allowed_fields_by_page_type', []);
        $this->assertArrayHasKey('brand_story', $config);
        $this->assertContains('identity.mission', $config['brand_story']);
        $this->assertContains('identity.positioning', $config['brand_story']);
    }
}
