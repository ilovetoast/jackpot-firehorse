<?php

namespace Tests\Feature;

use App\Services\Color\HueClusterService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HueClusterAssignmentTest extends TestCase
{
    protected HueClusterService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(HueClusterService::class);
    }

    #[Test]
    public function exact_brown_hex_returns_brown_cluster(): void
    {
        // #6D4C41 is cool_brown display hex - converts to LAB within threshold of cool_brown centroid
        $hex = '#6D4C41';
        $cluster = $this->service->assignClusterFromHex($hex);
        $this->assertSame('cool_brown', $cluster);
    }

    #[Test]
    public function slight_variation_brown_returns_cluster(): void
    {
        // Slight variation of cool brown - should still map to some cluster within threshold
        $hex = '#7A5A4D';
        $cluster = $this->service->assignClusterFromHex($hex);
        $this->assertNotNull($cluster);
        $this->assertIsString($cluster);
    }

    #[Test]
    public function green_returns_green_cluster(): void
    {
        $hex = '#43A047'; // Green display hex
        $cluster = $this->service->assignClusterFromHex($hex);
        $this->assertSame('green', $cluster);
    }

    #[Test]
    public function get_clusters_returns_max_18(): void
    {
        $clusters = $this->service->getClusters();
        $this->assertLessThanOrEqual(18, count($clusters));
        $this->assertGreaterThanOrEqual(1, count($clusters));
    }

    #[Test]
    public function assign_cluster_from_lab_works(): void
    {
        $lab = [45, 25, 45]; // Warm brown centroid
        $cluster = $this->service->assignClusterFromLab($lab);
        $this->assertSame('warm_brown', $cluster);
    }

    #[Test]
    public function get_cluster_meta_returns_correct_structure(): void
    {
        $meta = $this->service->getClusterMeta('warm_brown');
        $this->assertNotNull($meta);
        $this->assertArrayHasKey('key', $meta);
        $this->assertArrayHasKey('label', $meta);
        $this->assertArrayHasKey('lab_centroid', $meta);
        $this->assertArrayHasKey('display_hex', $meta);
        $this->assertSame('warm_brown', $meta['key']);
        $this->assertSame('Warm Brown', $meta['label']);
    }

    #[Test]
    public function get_cluster_meta_returns_null_for_unknown_key(): void
    {
        $meta = $this->service->getClusterMeta('unknown_cluster');
        $this->assertNull($meta);
    }

    #[Test]
    public function invalid_hex_returns_null(): void
    {
        $this->assertNull($this->service->assignClusterFromHex('invalid'));
        $this->assertNull($this->service->assignClusterFromHex(''));
        $this->assertNull($this->service->assignClusterFromHex('#GGGGGG'));
    }
}
