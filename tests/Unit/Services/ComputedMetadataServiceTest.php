<?php

namespace Tests\Unit\Services;

use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\ComputedMetadataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Computed Metadata Service Test
 *
 * Tests orientation detection with EXIF normalization and ratio-based classification.
 * Tests thumbnail-derived metadata fallback (extractImageDataFromThumbnail).
 */
class ComputedMetadataServiceTest extends TestCase
{
    use RefreshDatabase;
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
     * Test: Tall portrait image must be classified as portrait
     * A 1080x1920 image (typical phone portrait) should NEVER be square
     */
    public function test_tall_portrait_image_must_be_portrait(): void
    {
        // Common phone portrait dimensions
        $orientation = $this->callProtectedMethod('computeOrientation', [1080, 1920]);
        $this->assertEquals('portrait', $orientation, 'Tall portrait image (1080x1920) must be classified as portrait, not square');
        
        // Even taller portrait
        $orientation = $this->callProtectedMethod('computeOrientation', [800, 2400]);
        $this->assertEquals('portrait', $orientation, 'Very tall portrait image must be classified as portrait');
    }

    /**
     * Test: Wide landscape image must be classified as landscape
     * A 1920x1080 image (typical landscape) should NEVER be square
     */
    public function test_wide_landscape_image_must_be_landscape(): void
    {
        // Common landscape dimensions
        $orientation = $this->callProtectedMethod('computeOrientation', [1920, 1080]);
        $this->assertEquals('landscape', $orientation, 'Wide landscape image (1920x1080) must be classified as landscape, not square');
        
        // Even wider landscape
        $orientation = $this->callProtectedMethod('computeOrientation', [3840, 2160]);
        $this->assertEquals('landscape', $orientation, 'Very wide landscape image must be classified as landscape');
    }

    /**
     * Test: Image with bad or missing EXIF still computes orientation correctly
     * Orientation should be computed from pixel dimensions, not EXIF
     */
    public function test_missing_exif_still_computes_orientation(): void
    {
        // Portrait image with no EXIF
        [$width, $height] = $this->callProtectedMethod('normalizeDimensionsFromExif', [
            1080,
            1920,
            [] // No EXIF data
        ]);
        
        $this->assertEquals(1080, $width);
        $this->assertEquals(1920, $height);
        
        $orientation = $this->callProtectedMethod('computeOrientation', [$width, $height]);
        $this->assertEquals('portrait', $orientation, 'Portrait image without EXIF must still be classified as portrait');
    }

    /**
     * Test: Image with invalid EXIF orientation still uses stored dimensions
     * Invalid EXIF values should not break orientation detection
     */
    public function test_invalid_exif_orientation_uses_stored_dimensions(): void
    {
        // Invalid orientation value (should be 1-8)
        [$width, $height] = $this->callProtectedMethod('normalizeDimensionsFromExif', [
            1920,
            1080,
            ['Orientation' => 99] // Invalid value
        ]);
        
        // Should use stored dimensions (no swap for invalid orientation)
        $this->assertEquals(1920, $width);
        $this->assertEquals(1080, $height);
        
        $orientation = $this->callProtectedMethod('computeOrientation', [$width, $height]);
        $this->assertEquals('landscape', $orientation);
    }

    /**
     * Test: Non-square original with square-like dimensions
     * Edge case: Image that's close to square but clearly not square
     */
    public function test_near_square_but_clearly_landscape(): void
    {
        // 1200x1000 = 1.2 ratio (clearly landscape, not square)
        $orientation = $this->callProtectedMethod('computeOrientation', [1200, 1000]);
        $this->assertEquals('landscape', $orientation, 'Image with 1.2 ratio must be landscape, not square');
    }

    /**
     * Test: Non-square original with square-like dimensions (portrait)
     * Edge case: Image that's close to square but clearly not square
     */
    public function test_near_square_but_clearly_portrait(): void
    {
        // 1000x1200 = 0.833 ratio (clearly portrait, not square)
        $orientation = $this->callProtectedMethod('computeOrientation', [1000, 1200]);
        $this->assertEquals('portrait', $orientation, 'Image with 0.833 ratio must be portrait, not square');
    }

    /**
     * Test: All EXIF orientations that don't require swap
     * Orientations 2, 3, 4, 5, 7 should not swap dimensions
     */
    public function test_exif_orientations_without_swap(): void
    {
        $orientations = [2, 3, 4, 5, 7];
        
        foreach ($orientations as $exifOrientation) {
            [$width, $height] = $this->callProtectedMethod('normalizeDimensionsFromExif', [
                1920,
                1080,
                ['Orientation' => $exifOrientation]
            ]);
            
            $this->assertEquals(1920, $width, "EXIF orientation {$exifOrientation} should not swap width");
            $this->assertEquals(1080, $height, "EXIF orientation {$exifOrientation} should not swap height");
        }
    }

    /**
     * Test: extractImageDataFromThumbnail returns dimensions when asset supports thumbnail metadata
     */
    public function test_extract_image_data_from_thumbnail_returns_dimensions(): void
    {
        $asset = $this->createAssetWithThumbnailDimensions(800, 600);
        $result = $this->callProtectedMethod('extractImageDataFromThumbnail', [$asset]);

        $this->assertNotNull($result);
        $this->assertEquals(800, $result['width']);
        $this->assertEquals(600, $result['height']);
        $this->assertEquals([], $result['exif']);
    }

    /**
     * Test: extractImageDataFromThumbnail returns null when thumbnail_timeout is true
     */
    public function test_extract_image_data_from_thumbnail_returns_null_when_thumbnail_timeout(): void
    {
        $asset = $this->createAssetWithThumbnailDimensions(800, 600);
        $metadata = $asset->metadata ?? [];
        $metadata['thumbnail_timeout'] = true;
        $asset->metadata = $metadata;

        $result = $this->callProtectedMethod('extractImageDataFromThumbnail', [$asset]);

        $this->assertNull($result);
    }

    /**
     * Test: extractImageDataFromThumbnail returns null when thumbnail_status is not completed
     */
    public function test_extract_image_data_from_thumbnail_returns_null_when_status_not_completed(): void
    {
        $asset = $this->createAssetWithThumbnailDimensions(800, 600);
        $asset->thumbnail_status = ThumbnailStatus::PENDING;

        $result = $this->callProtectedMethod('extractImageDataFromThumbnail', [$asset]);

        $this->assertNull($result);
    }

    /**
     * Test: extractImageDataFromThumbnail returns null when dimensions are missing
     */
    public function test_extract_image_data_from_thumbnail_returns_null_when_dimensions_missing(): void
    {
        $asset = $this->createAssetWithThumbnailDimensions(800, 600);
        $metadata = $asset->metadata ?? [];
        unset($metadata['thumbnail_dimensions']);
        $asset->metadata = $metadata;

        $result = $this->callProtectedMethod('extractImageDataFromThumbnail', [$asset]);

        $this->assertNull($result);
    }

    protected function createAssetWithThumbnailDimensions(int $width, int $height): Asset
    {
        $tenant = Tenant::create(['name' => 'Test', 'slug' => 'test']);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'Test', 'slug' => 'test']);
        $user = User::create([
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Test',
            'last_name' => 'User',
        ]);
        $bucket = StorageBucket::create([
            'tenant_id' => $tenant->id,
            'name' => 'test',
            'status' => \App\Enums\StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
        $session = UploadSession::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'storage_bucket_id' => $bucket->id,
            'status' => \App\Enums\UploadStatus::COMPLETED,
            'type' => \App\Enums\UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        return Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'upload_session_id' => $session->id,
            'storage_bucket_id' => $bucket->id,
            'type' => \App\Enums\AssetType::ASSET,
            'status' => \App\Enums\AssetStatus::VISIBLE,
            'mime_type' => 'image/jpeg',
            'original_filename' => 'test.jpg',
            'storage_root_path' => 'temp/test.jpg',
            'thumbnail_status' => ThumbnailStatus::COMPLETED,
            'metadata' => [
                'thumbnails' => [
                    'medium' => ['path' => 'assets/thumbnails/medium/test.jpg'],
                ],
                'thumbnail_dimensions' => [
                    'medium' => ['width' => $width, 'height' => $height],
                ],
            ],
        ]);
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
