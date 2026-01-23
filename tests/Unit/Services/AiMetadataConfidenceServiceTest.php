<?php

namespace Tests\Unit\Services;

use App\Services\AiMetadataConfidenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AI Metadata Confidence Service Test
 *
 * Tests confidence threshold checking for AI-generated metadata suppression.
 * This is a PRESENTATION + QUERY-LAYER test only - no schema or metadata mutations.
 */
class AiMetadataConfidenceServiceTest extends TestCase
{
    use RefreshDatabase;

    protected AiMetadataConfidenceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AiMetadataConfidenceService();
    }

    /**
     * Test: High-confidence AI values are visible
     */
    public function test_high_confidence_ai_values_are_visible(): void
    {
        // ai_color_palette threshold is 0.80
        $this->assertFalse($this->service->shouldSuppress('ai_color_palette', 0.85));
        $this->assertTrue($this->service->shouldShow('ai_color_palette', 0.85));

        // ai_detected_objects threshold is 0.70
        $this->assertFalse($this->service->shouldSuppress('ai_detected_objects', 0.75));
        $this->assertTrue($this->service->shouldShow('ai_detected_objects', 0.75));

        // scene_classification threshold is 0.75
        $this->assertFalse($this->service->shouldSuppress('scene_classification', 0.80));
        $this->assertTrue($this->service->shouldShow('scene_classification', 0.80));
    }

    /**
     * Test: Low-confidence values are suppressed
     */
    public function test_low_confidence_values_are_suppressed(): void
    {
        // ai_color_palette threshold is 0.80
        $this->assertTrue($this->service->shouldSuppress('ai_color_palette', 0.75));
        $this->assertFalse($this->service->shouldShow('ai_color_palette', 0.75));

        // ai_detected_objects threshold is 0.70
        $this->assertTrue($this->service->shouldSuppress('ai_detected_objects', 0.65));
        $this->assertFalse($this->service->shouldShow('ai_detected_objects', 0.65));

        // scene_classification threshold is 0.75
        $this->assertTrue($this->service->shouldSuppress('scene_classification', 0.70));
        $this->assertFalse($this->service->shouldShow('scene_classification', 0.70));
    }

    /**
     * Test: Values at exact threshold are visible (>= threshold)
     */
    public function test_values_at_threshold_are_visible(): void
    {
        // At threshold should be visible (>= threshold)
        $this->assertFalse($this->service->shouldSuppress('ai_color_palette', 0.80));
        $this->assertFalse($this->service->shouldSuppress('ai_detected_objects', 0.70));
        $this->assertFalse($this->service->shouldSuppress('scene_classification', 0.75));
    }

    /**
     * Test: Deterministic system fields are unaffected
     */
    public function test_deterministic_system_fields_are_unaffected(): void
    {
        // Non-AI fields should never be suppressed, regardless of confidence
        $this->assertFalse($this->service->shouldSuppress('orientation', 0.50));
        $this->assertFalse($this->service->shouldSuppress('resolution', 0.30));
        $this->assertFalse($this->service->shouldSuppress('file_size', null));
        $this->assertFalse($this->service->shouldSuppress('mime_type', 0.0));

        // Should always show
        $this->assertTrue($this->service->shouldShow('orientation', 0.50));
        $this->assertTrue($this->service->shouldShow('resolution', null));
    }

    /**
     * Test: Missing confidence data is safely suppressed
     */
    public function test_missing_confidence_data_is_suppressed(): void
    {
        // Null confidence should be suppressed for AI fields
        $this->assertTrue($this->service->shouldSuppress('ai_color_palette', null));
        $this->assertTrue($this->service->shouldSuppress('ai_detected_objects', null));
        $this->assertTrue($this->service->shouldSuppress('scene_classification', null));

        // Should not show
        $this->assertFalse($this->service->shouldShow('ai_color_palette', null));
    }

    /**
     * Test: Invalid confidence values are suppressed
     */
    public function test_invalid_confidence_values_are_suppressed(): void
    {
        // Negative values (treated as below threshold)
        $this->assertTrue($this->service->shouldSuppress('ai_color_palette', -0.5));

        // Values above 1.0 (should still work, but unlikely)
        $this->assertFalse($this->service->shouldSuppress('ai_color_palette', 1.5));
    }

    /**
     * Test: isAiMetadataField correctly identifies AI fields
     */
    public function test_is_ai_metadata_field_identification(): void
    {
        $this->assertTrue($this->service->isAiMetadataField('ai_color_palette'));
        $this->assertTrue($this->service->isAiMetadataField('ai_detected_objects'));
        $this->assertTrue($this->service->isAiMetadataField('scene_classification'));

        $this->assertFalse($this->service->isAiMetadataField('orientation'));
        $this->assertFalse($this->service->isAiMetadataField('resolution'));
        $this->assertFalse($this->service->isAiMetadataField('file_size'));
    }

    /**
     * Test: getThreshold returns correct threshold values
     */
    public function test_get_threshold_returns_correct_values(): void
    {
        $this->assertEquals(0.80, $this->service->getThreshold('ai_color_palette'));
        $this->assertEquals(0.70, $this->service->getThreshold('ai_detected_objects'));
        $this->assertEquals(0.75, $this->service->getThreshold('scene_classification'));

        // Non-AI fields return default threshold of 1.0
        $this->assertEquals(1.0, $this->service->getThreshold('orientation'));
    }

    /**
     * Test: Deterministic behavior (same input = same output)
     */
    public function test_deterministic_behavior(): void
    {
        // Same field + same confidence = same result
        $result1 = $this->service->shouldSuppress('ai_color_palette', 0.75);
        $result2 = $this->service->shouldSuppress('ai_color_palette', 0.75);
        $this->assertEquals($result1, $result2);

        // Different confidence = different result
        $result3 = $this->service->shouldSuppress('ai_color_palette', 0.85);
        $this->assertNotEquals($result1, $result3);
    }
}
