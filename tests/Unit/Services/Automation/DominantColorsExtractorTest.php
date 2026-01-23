<?php

namespace Tests\Unit\Services\Automation;

use App\Models\Asset;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\Brand;
use App\Models\UploadSession;
use App\Services\Automation\DominantColorsExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dominant Colors Extractor Test
 *
 * Tests extraction and persistence of top 3 dominant colors from color cluster data.
 * 
 * @runInSeparateProcess
 * @preserveGlobalState disabled
 */
class DominantColorsExtractorTest extends TestCase
{
    use RefreshDatabase;

    protected DominantColorsExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new DominantColorsExtractor();
    }

    protected function tearDown(): void
    {
        // Reset any config mutations to prevent cross-test contamination
        // This ensures tests don't affect each other through shared config state
        config(['color.max_colors' => null]);
        parent::tearDown();
    }

    /**
     * Test: Uses existing color clusters
     */
    public function test_uses_existing_color_clusters(): void
    {
        $asset = $this->createAssetWithClusters([
            ['rgb' => [31, 58, 138], 'coverage' => 0.42],
            ['rgb' => [17, 24, 39], 'coverage' => 0.31],
            ['rgb' => [249, 250, 251], 'coverage' => 0.15],
        ]);

        $this->extractor->extractAndPersist($asset);
        $asset->refresh();

        $dominantColors = $asset->metadata['dominant_colors'] ?? null;
        $this->assertNotNull($dominantColors);
        $this->assertCount(3, $dominantColors);
    }

    /**
     * Test: Correct hex conversion
     */
    public function test_correct_hex_conversion(): void
    {
        $asset = $this->createAssetWithClusters([
            ['rgb' => [31, 58, 138], 'coverage' => 0.42],
        ]);

        $this->extractor->extractAndPersist($asset);
        $asset->refresh();

        $colors = $asset->metadata['dominant_colors'] ?? [];
        $this->assertNotEmpty($colors);
        $this->assertEquals('#1F3A8A', $colors[0]['hex']);
        $this->assertEquals([31, 58, 138], $colors[0]['rgb']);
    }

    /**
     * Test: Coverage threshold enforcement (>= 10%)
     */
    public function test_coverage_threshold_enforcement(): void
    {
        $asset = $this->createAssetWithClusters([
            ['rgb' => [31, 58, 138], 'coverage' => 0.42],
            ['rgb' => [17, 24, 39], 'coverage' => 0.31],
            ['rgb' => [249, 250, 251], 'coverage' => 0.15],
            ['rgb' => [255, 0, 0], 'coverage' => 0.05], // Below 10% threshold
            ['rgb' => [0, 255, 0], 'coverage' => 0.09], // Below 10% threshold
        ]);

        $this->extractor->extractAndPersist($asset);
        $asset->refresh();

        $colors = $asset->metadata['dominant_colors'] ?? [];
        $this->assertCount(3, $colors); // Only top 3 above threshold
        $this->assertEquals(0.42, $colors[0]['coverage']);
        $this->assertEquals(0.31, $colors[1]['coverage']);
        $this->assertEquals(0.15, $colors[2]['coverage']);
    }

    /**
     * Test: Max 3 colors enforced
     */
    public function test_max_3_colors_enforced(): void
    {
        $asset = $this->createAssetWithClusters([
            ['rgb' => [31, 58, 138], 'coverage' => 0.42],
            ['rgb' => [17, 24, 39], 'coverage' => 0.31],
            ['rgb' => [249, 250, 251], 'coverage' => 0.15],
            ['rgb' => [255, 255, 0], 'coverage' => 0.12],
            ['rgb' => [0, 255, 255], 'coverage' => 0.11],
        ]);

        $this->extractor->extractAndPersist($asset);
        $asset->refresh();

        $colors = $asset->metadata['dominant_colors'] ?? [];
        $this->assertCount(3, $colors); // Max 3
    }

    /**
     * Test: Deterministic ordering (by coverage descending)
     */
    public function test_deterministic_ordering(): void
    {
        $asset = $this->createAssetWithClusters([
            ['rgb' => [249, 250, 251], 'coverage' => 0.15],
            ['rgb' => [31, 58, 138], 'coverage' => 0.42],
            ['rgb' => [17, 24, 39], 'coverage' => 0.31],
        ]);

        $this->extractor->extractAndPersist($asset);
        $asset->refresh();

        $colors = $asset->metadata['dominant_colors'] ?? [];
        $this->assertCount(3, $colors);
        // Should be sorted by coverage descending
        $this->assertEquals(0.42, $colors[0]['coverage']);
        $this->assertEquals(0.31, $colors[1]['coverage']);
        $this->assertEquals(0.15, $colors[2]['coverage']);
    }

    /**
     * Test: Graceful no-op when cluster data missing
     */
    public function test_graceful_noop_when_cluster_data_missing(): void
    {
        $asset = $this->createAssetWithMetadata([]);

        // Should not throw
        $this->extractor->extractAndPersist($asset);
        $asset->refresh();

        $this->assertArrayNotHasKey('dominant_colors', $asset->metadata ?? []);
    }

    /**
     * Test: Empty clusters array
     */
    public function test_empty_clusters_array(): void
    {
        $asset = $this->createAssetWithMetadata([
            '_color_analysis' => [
                'clusters' => [],
            ],
        ]);

        $this->extractor->extractAndPersist($asset);
        $asset->refresh();

        $this->assertArrayNotHasKey('dominant_colors', $asset->metadata ?? []);
    }

    /**
     * Test: RGB values clamped to 0-255
     */
    public function test_rgb_values_clamped(): void
    {
        $asset = $this->createAssetWithClusters([
            ['rgb' => [300, -10, 138], 'coverage' => 0.42], // Out of range values
        ]);

        $this->extractor->extractAndPersist($asset);
        $asset->refresh();

        $colors = $asset->metadata['dominant_colors'] ?? [];
        $this->assertNotEmpty($colors);
        $rgb = $colors[0]['rgb'];
        $this->assertEquals(255, $rgb[0]); // Clamped from 300
        $this->assertEquals(0, $rgb[1]); // Clamped from -10
        $this->assertEquals(138, $rgb[2]); // Within range
    }

    /**
     * Test: Hex format with leading zeros
     */
    public function test_hex_format_with_leading_zeros(): void
    {
        $asset = $this->createAssetWithClusters([
            ['rgb' => [1, 2, 3], 'coverage' => 0.42], // Small values requiring leading zeros
        ]);

        $this->extractor->extractAndPersist($asset);
        $asset->refresh();

        $colors = $asset->metadata['dominant_colors'] ?? [];
        $this->assertEquals('#010203', $colors[0]['hex']); // Leading zeros preserved
    }

    /**
     * Test: Coverage preserved as float
     */
    public function test_coverage_preserved_as_float(): void
    {
        $asset = $this->createAssetWithClusters([
            ['rgb' => [31, 58, 138], 'coverage' => 0.421234567], // Precise float
        ]);

        $this->extractor->extractAndPersist($asset);
        $asset->refresh();

        $colors = $asset->metadata['dominant_colors'] ?? [];
        $this->assertIsFloat($colors[0]['coverage']);
        $this->assertEquals(0.421234567, $colors[0]['coverage']);
    }

    /**
     * Test: Fewer than 3 clusters above threshold
     */
    public function test_fewer_than_3_clusters_above_threshold(): void
    {
        $asset = $this->createAssetWithClusters([
            ['rgb' => [31, 58, 138], 'coverage' => 0.42],
            ['rgb' => [17, 24, 39], 'coverage' => 0.31],
            // Only 2 clusters above 10% threshold
        ]);

        $this->extractor->extractAndPersist($asset);
        $asset->refresh();

        $colors = $asset->metadata['dominant_colors'] ?? [];
        $this->assertCount(2, $colors); // Should return 2, not pad to 3
    }

    /**
     * Test: Exactly 10% coverage threshold boundary
     */
    public function test_exactly_10_percent_coverage_threshold(): void
    {
        $asset = $this->createAssetWithClusters([
            ['rgb' => [31, 58, 138], 'coverage' => 0.10], // Exactly 10%
            ['rgb' => [17, 24, 39], 'coverage' => 0.09], // Just below 10%
        ]);

        $this->extractor->extractAndPersist($asset);
        $asset->refresh();

        $colors = $asset->metadata['dominant_colors'] ?? [];
        $this->assertCount(1, $colors); // Only the 10% one
        $this->assertEquals(0.10, $colors[0]['coverage']);
    }

    /**
     * Test: Non-image asset skipped
     */
    public function test_non_image_asset_skipped(): void
    {
        $asset = $this->createAssetWithMetadata([
            '_color_analysis' => [
                'clusters' => [
                    ['rgb' => [31, 58, 138], 'coverage' => 0.42],
                ],
            ],
        ], 'application/pdf');

        $this->extractor->extractAndPersist($asset);
        $asset->refresh();

        $this->assertArrayNotHasKey('dominant_colors', $asset->metadata ?? []);
    }

    /**
     * Helper: Create asset with color clusters in metadata.
     *
     * @param array $clusters
     * @return Asset
     */
    protected function createAssetWithClusters(array $clusters): Asset
    {
        return $this->createAssetWithMetadata([
            '_color_analysis' => [
                'clusters' => $clusters,
                'ignored_pixels' => 0.0,
            ],
        ], 'image/jpeg');
    }

    /**
     * Helper: Create asset with metadata.
     * Forces clean metadata at create (no cross-test contamination).
     *
     * @param array $metadata
     * @param string $mimeType
     * @return Asset
     */
    protected function createAssetWithMetadata(array $metadata, string $mimeType = 'image/jpeg'): Asset
    {
        // Create minimal tenant and brand if they don't exist
        $tenant = Tenant::firstOrCreate(['id' => 1], [
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);
        
        $brand = Brand::firstOrCreate(['id' => 1, 'tenant_id' => 1], [
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);
        
        $storageBucket = StorageBucket::firstOrCreate(['id' => 1, 'tenant_id' => 1], [
            'name' => 'test-bucket',
            'status' => \App\Enums\StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
        
        $uploadSession = UploadSession::create([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'storage_bucket_id' => $storageBucket->id,
            'status' => \App\Enums\UploadStatus::COMPLETED,
            'type' => \App\Enums\UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);
        
        $asset = Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'upload_session_id' => $uploadSession->id,
            'storage_bucket_id' => $storageBucket->id,
            'mime_type' => $mimeType,
            'original_filename' => 'test.jpg',
            'size_bytes' => 1024,
            'storage_root_path' => 'test/path.jpg',
            'metadata' => $metadata,
            'status' => \App\Enums\AssetStatus::VISIBLE,
            'type' => \App\Enums\AssetType::ASSET,
        ]);
        
        // Force clean metadata at test start (guarantees no cross-test contamination)
        $asset->update(['metadata' => $metadata]);
        $asset->refresh();
        
        return $asset;
    }
}
