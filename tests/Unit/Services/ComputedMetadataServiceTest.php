<?php

namespace Tests\Unit\Services;

use App\Services\ComputedMetadataService;
use Tests\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Computed Metadata Service Test
 *
 * Tests orientation detection with EXIF normalization and ratio-based classification.
 */
class ComputedMetadataServiceTest extends TestCase
{
    protected ComputedMetadataService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ComputedMetadataService();
    }

    /**
     * Test: Landscape photo incorrectly marked square (regression test)
     * A 1920x1080 image should be landscape, not square
     */
    public function test_landscape_image_not_marked_square(): void
    {
        $orientation = $this->callProtectedMethod('computeOrientation', [1920, 1080]);
        
        $this->assertEquals('landscape', $orientation);
    }

    /**
     * Test: Portrait image with EXIF rotation
     * Image stored as 1920x1080 but EXIF orientation 6 (90° CW) should be portrait
     */
    public function test_portrait_with_exif_rotation(): void
    {
        // Stored dimensions: 1920x1080 (landscape)
        // EXIF orientation 6: 90° CW rotation → visual dimensions should be 1080x1920 (portrait)
        [$width, $height] = $this->callProtectedMethod('normalizeDimensionsFromExif', [
            1920,
            1080,
            ['Orientation' => 6]
        ]);
        
        $this->assertEquals(1080, $width);
        $this->assertEquals(1920, $height);
        
        // After normalization, should be portrait
        $orientation = $this->callProtectedMethod('computeOrientation', [$width, $height]);
        $this->assertEquals('portrait', $orientation);
    }

    /**
     * Test: True square image
     * A 1024x1024 image should be square
     */
    public function test_true_square_image(): void
    {
        $orientation = $this->callProtectedMethod('computeOrientation', [1024, 1024]);
        
        $this->assertEquals('square', $orientation);
    }

    /**
     * Test: Near-square image classified correctly
     * A 1024x980 image (ratio ~1.045) should be square (within 0.95-1.05 range)
     */
    public function test_near_square_image_classified_correctly(): void
    {
        // 1024 / 980 = 1.0449 (within 0.95-1.05 range)
        $orientation = $this->callProtectedMethod('computeOrientation', [1024, 980]);
        
        $this->assertEquals('square', $orientation);
    }

    /**
     * Test: Near-square but slightly landscape
     * A 1024x950 image (ratio ~1.078) should be landscape (outside 0.95-1.05 range)
     */
    public function test_near_square_but_landscape(): void
    {
        // 1024 / 950 = 1.0779 (outside 0.95-1.05 range)
        $orientation = $this->callProtectedMethod('computeOrientation', [1024, 950]);
        
        $this->assertEquals('landscape', $orientation);
    }

    /**
     * Test: Near-square but slightly portrait
     * A 980x1024 image (ratio ~0.957) should be square (within 0.95-1.05 range)
     */
    public function test_near_square_but_portrait(): void
    {
        // 980 / 1024 = 0.957 (within 0.95-1.05 range)
        $orientation = $this->callProtectedMethod('computeOrientation', [980, 1024]);
        
        $this->assertEquals('square', $orientation);
    }

    /**
     * Test: EXIF orientation 8 (90° CCW rotation)
     * Image stored as 1920x1080 but EXIF orientation 8 should swap dimensions
     */
    public function test_exif_orientation_8_swaps_dimensions(): void
    {
        [$width, $height] = $this->callProtectedMethod('normalizeDimensionsFromExif', [
            1920,
            1080,
            ['Orientation' => 8]
        ]);
        
        $this->assertEquals(1080, $width);
        $this->assertEquals(1920, $height);
    }

    /**
     * Test: EXIF orientation 3 (180° rotation)
     * No dimension swap needed (just rotation)
     */
    public function test_exif_orientation_3_no_swap(): void
    {
        [$width, $height] = $this->callProtectedMethod('normalizeDimensionsFromExif', [
            1920,
            1080,
            ['Orientation' => 3]
        ]);
        
        $this->assertEquals(1920, $width);
        $this->assertEquals(1080, $height);
    }

    /**
     * Test: Missing EXIF orientation uses stored dimensions
     */
    public function test_missing_exif_uses_stored_dimensions(): void
    {
        [$width, $height] = $this->callProtectedMethod('normalizeDimensionsFromExif', [
            1920,
            1080,
            []
        ]);
        
        $this->assertEquals(1920, $width);
        $this->assertEquals(1080, $height);
    }

    /**
     * Test: EXIF orientation 1 (normal) uses stored dimensions
     */
    public function test_exif_orientation_1_uses_stored_dimensions(): void
    {
        [$width, $height] = $this->callProtectedMethod('normalizeDimensionsFromExif', [
            1920,
            1080,
            ['Orientation' => 1]
        ]);
        
        $this->assertEquals(1920, $width);
        $this->assertEquals(1080, $height);
    }

    /**
     * Test: EXIF orientation in IFD0 subarray
     */
    public function test_exif_orientation_in_ifd0(): void
    {
        [$width, $height] = $this->callProtectedMethod('normalizeDimensionsFromExif', [
            1920,
            1080,
            ['IFD0' => ['Orientation' => 6]]
        ]);
        
        $this->assertEquals(1080, $width);
        $this->assertEquals(1920, $height);
    }

    /**
     * Test: Zero dimensions return null
     */
    public function test_zero_dimensions_return_null(): void
    {
        $orientation = $this->callProtectedMethod('computeOrientation', [0, 1080]);
        
        $this->assertNull($orientation);
        
        $orientation = $this->callProtectedMethod('computeOrientation', [1920, 0]);
        
        $this->assertNull($orientation);
    }

    /**
     * Test: Boundary cases for ratio classification
     */
    public function test_ratio_boundary_cases(): void
    {
        // Exactly 0.95 ratio (should be square)
        $orientation = $this->callProtectedMethod('computeOrientation', [950, 1000]);
        $this->assertEquals('square', $orientation);
        
        // Exactly 1.05 ratio (should be square)
        $orientation = $this->callProtectedMethod('computeOrientation', [1050, 1000]);
        $this->assertEquals('square', $orientation);
        
        // Just below 0.95 (should be portrait)
        $orientation = $this->callProtectedMethod('computeOrientation', [949, 1000]);
        $this->assertEquals('portrait', $orientation);
        
        // Just above 1.05 (should be landscape)
        $orientation = $this->callProtectedMethod('computeOrientation', [1051, 1000]);
        $this->assertEquals('landscape', $orientation);
    }

    /**
     * Helper method to call protected methods for testing.
     *
     * @param string $methodName
     * @param array $args
     * @return mixed
     */
    protected function callProtectedMethod(string $methodName, array $args)
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        
        return $method->invokeArgs($this->service, $args);
    }
}
