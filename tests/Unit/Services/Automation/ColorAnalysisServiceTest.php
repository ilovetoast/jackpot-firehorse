<?php

namespace Tests\Unit\Services\Automation;

use App\Services\Automation\ColorAnalysisService;
use Tests\TestCase;

/**
 * Color Analysis Service Test
 *
 * Tests deterministic color analysis for ai_color_palette metadata.
 * Validates algorithm correctness and deterministic output.
 *
 * Test cases:
 * 1. White background with small logo
 * 2. Product on neutral background
 * 3. Image with sky and foreground
 * 4. Black and white image
 */

/**
 * Color Analysis Service Test
 *
 * Tests deterministic color analysis for ai_color_palette metadata.
 * Validates algorithm correctness and deterministic output.
 */
class ColorAnalysisServiceTest extends TestCase
{
    protected ColorAnalysisService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ColorAnalysisService();
    }

    /**
     * Test: White background with small logo
     * Expected: White bucket, possibly logo color if coverage >= 8%
     */
    public function test_white_background_with_small_logo(): void
    {
        $path = $this->createTestImage(200, 200, [
            'background' => [255, 255, 255], // White
            'shapes' => [
                ['x' => 90, 'y' => 90, 'w' => 20, 'h' => 20, 'color' => [255, 0, 0]], // Small red square
            ],
        ]);

        $result = $this->service->analyzeFromPath($path);
        
        $this->assertNotNull($result);
        $this->assertArrayHasKey('buckets', $result);
        $this->assertArrayHasKey('internal', $result);
        $this->assertContains('white', $result['buckets']);
        
        // Small logo should not appear if coverage < 8%
        // White should dominate
        $this->assertGreaterThan(0, count($result['buckets']));
        
        @unlink($path);
    }

    /**
     * Test: Product on neutral background
     * Expected: Neutral (gray/white) + product colors
     */
    public function test_product_on_neutral_background(): void
    {
        $path = $this->createTestImage(200, 200, [
            'background' => [200, 200, 200], // Light gray
            'shapes' => [
                ['x' => 50, 'y' => 50, 'w' => 100, 'h' => 100, 'color' => [0, 128, 255]], // Blue product
            ],
        ]);

        $result = $this->service->analyzeFromPath($path);
        
        $this->assertNotNull($result);
        $this->assertArrayHasKey('buckets', $result);
        $this->assertArrayHasKey('internal', $result);
        
        // Should have at least gray/white and blue
        $this->assertGreaterThanOrEqual(1, count($result['buckets']));
        $this->assertLessThanOrEqual(4, count($result['buckets']));
        
        @unlink($path);
    }

    /**
     * Test: Image with sky and foreground
     * Expected: Blue (sky) + foreground colors
     */
    public function test_sky_and_foreground(): void
    {
        $path = $this->createTestImage(200, 200, [
            'background' => [135, 206, 250], // Sky blue
            'shapes' => [
                ['x' => 0, 'y' => 100, 'w' => 200, 'h' => 100, 'color' => [34, 139, 34]], // Green foreground
            ],
        ]);

        $result = $this->service->analyzeFromPath($path);
        
        $this->assertNotNull($result);
        $this->assertArrayHasKey('buckets', $result);
        $this->assertArrayHasKey('internal', $result);
        
        // Should detect blue (sky) and green (foreground)
        $this->assertGreaterThanOrEqual(1, count($result['buckets']));
        $this->assertLessThanOrEqual(4, count($result['buckets']));
        
        // Verify internal cluster data structure
        $this->assertArrayHasKey('clusters', $result['internal']);
        $this->assertArrayHasKey('ignored_pixels', $result['internal']);
        $this->assertIsFloat($result['internal']['ignored_pixels']);
        
        @unlink($path);
    }

    /**
     * Test: Black and white image
     * Expected: Black and/or white buckets, possibly gray
     */
    public function test_black_and_white_image(): void
    {
        $path = $this->createTestImage(200, 200, [
            'background' => [255, 255, 255], // White
            'shapes' => [
                ['x' => 50, 'y' => 50, 'w' => 100, 'h' => 100, 'color' => [0, 0, 0]], // Black square
            ],
        ]);

        $result = $this->service->analyzeFromPath($path);
        
        $this->assertNotNull($result);
        $this->assertArrayHasKey('buckets', $result);
        
        // Should detect black and/or white
        $hasBlackOrWhite = false;
        foreach ($result['buckets'] as $bucket) {
            if (in_array($bucket, ['black', 'white', 'gray'], true)) {
                $hasBlackOrWhite = true;
                break;
            }
        }
        $this->assertTrue($hasBlackOrWhite, 'Should detect black, white, or gray in B&W image');
        
        // Verify deterministic: same image should produce same result
        $result2 = $this->service->analyzeFromPath($path);
        $this->assertEquals($result['buckets'], $result2['buckets']);
        
        @unlink($path);
    }

    /**
     * Test: Deterministic output for identical images
     */
    public function test_deterministic_output(): void
    {
        $path = $this->createTestImage(200, 200, [
            'background' => [255, 0, 0], // Red
        ]);

        $result1 = $this->service->analyzeFromPath($path);
        $result2 = $this->service->analyzeFromPath($path);
        
        $this->assertEquals($result1['buckets'], $result2['buckets']);
        $this->assertEquals(count($result1['internal']['clusters']), count($result2['internal']['clusters']));
        
        @unlink($path);
    }

    /**
     * Test: Maximum 4 buckets per asset
     */
    public function test_max_buckets_limit(): void
    {
        $path = $this->createTestImage(200, 200, [
            'background' => [255, 255, 255], // White
            'shapes' => [
                ['x' => 0, 'y' => 0, 'w' => 50, 'h' => 50, 'color' => [255, 0, 0]], // Red
                ['x' => 50, 'y' => 0, 'w' => 50, 'h' => 50, 'color' => [0, 255, 0]], // Green
                ['x' => 100, 'y' => 0, 'w' => 50, 'h' => 50, 'color' => [0, 0, 255]], // Blue
                ['x' => 150, 'y' => 0, 'w' => 50, 'h' => 50, 'color' => [255, 255, 0]], // Yellow
                ['x' => 0, 'y' => 50, 'w' => 50, 'h' => 50, 'color' => [255, 0, 255]], // Magenta
            ],
        ]);

        $result = $this->service->analyzeFromPath($path);
        
        $this->assertLessThanOrEqual(4, count($result['buckets']));
        
        @unlink($path);
    }

    /**
     * Test: Minimum 8% coverage for bucket inclusion
     */
    public function test_min_coverage_threshold(): void
    {
        // Create image with very small colored area (< 8%)
        $path = $this->createTestImage(200, 200, [
            'background' => [255, 255, 255], // White (dominant)
            'shapes' => [
                ['x' => 90, 'y' => 90, 'w' => 5, 'h' => 5, 'color' => [255, 0, 0]], // Tiny red (< 1%)
            ],
        ]);

        $result = $this->service->analyzeFromPath($path);
        
        // Tiny red should not appear as bucket (coverage < 8%)
        // Only white should appear
        $this->assertContains('white', $result['buckets']);
        $this->assertNotContains('red', $result['buckets']);
        
        @unlink($path);
    }

    /**
     * Create a test image programmatically using GD.
     *
     * @param int $width
     * @param int $height
     * @param array $config {background: [r,g,b], shapes: [{x,y,w,h,color: [r,g,b]}]}
     * @return string Temporary file path
     */
    protected function createTestImage(int $width, int $height, array $config): string
    {
        $img = imagecreatetruecolor($width, $height);
        if (!$img) {
            $this->fail('Failed to create test image');
        }

        // Fill background
        $bg = $config['background'] ?? [255, 255, 255];
        $bgColor = imagecolorallocate($img, $bg[0], $bg[1], $bg[2]);
        imagefill($img, 0, 0, $bgColor);

        // Draw shapes
        foreach ($config['shapes'] ?? [] as $shape) {
            $color = imagecolorallocate($img, $shape['color'][0], $shape['color'][1], $shape['color'][2]);
            imagefilledrectangle(
                $img,
                $shape['x'],
                $shape['y'],
                $shape['x'] + $shape['w'] - 1,
                $shape['y'] + $shape['h'] - 1,
                $color
            );
        }

        $path = tempnam(sys_get_temp_dir(), 'color_test_') . '.png';
        imagepng($img, $path);
        imagedestroy($img);

        return $path;
    }
}
