<?php

namespace Tests\Unit\Jobs;

use App\Jobs\MergeBrandPdfExtractionJob;
use ReflectionClass;
use Tests\TestCase;

/**
 * Fallback narrative routing from page title/OCR.
 */
class MergeBrandPdfExtractionFallbackRoutingTest extends TestCase
{
    public function test_explicit_page_title_fallback_enables_narrative_routing(): void
    {
        $job = new MergeBrandPdfExtractionJob('test-batch');
        $method = (new ReflectionClass($job))->getMethod('getEligibleFieldsForPage');
        $result = $method->invoke($job, 'unknown', 'Our Purpose and Mission', null);
        $this->assertContains('identity.mission', $result);
        $this->assertContains('identity.vision', $result);
    }

    public function test_positioning_in_title_widens_eligible_fields(): void
    {
        $job = new MergeBrandPdfExtractionJob('test-batch');
        $method = (new ReflectionClass($job))->getMethod('getEligibleFieldsForPage');
        $result = $method->invoke($job, 'strategy', 'Brand Positioning', null);
        $this->assertContains('identity.positioning', $result);
        $this->assertContains('identity.industry', $result);
    }

    public function test_brand_voice_in_ocr_widens_eligible_fields(): void
    {
        $job = new MergeBrandPdfExtractionJob('test-batch');
        $method = (new ReflectionClass($job))->getMethod('getEligibleFieldsForPage');
        $result = $method->invoke($job, 'unknown', null, 'This page describes our BRAND VOICE and tone.');
        $this->assertContains('personality.tone_keywords', $result);
        $this->assertContains('personality.traits', $result);
    }

    public function test_no_fallback_when_no_cues(): void
    {
        $job = new MergeBrandPdfExtractionJob('test-batch');
        $method = (new ReflectionClass($job))->getMethod('getEligibleFieldsForPage');
        $result = $method->invoke($job, 'unknown', 'Cover Page', 'Just some generic text.');
        $this->assertEmpty($result);
    }
}
