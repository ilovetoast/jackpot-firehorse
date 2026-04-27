<?php

namespace Tests\Unit\Services\Assets;

use App\Models\Asset;
use App\Models\AssetVersion;
use App\Services\Assets\AssetProcessingBudgetService;
use App\Services\Assets\ProcessingBudgetDecision;
use Tests\TestCase;

class AssetProcessingBudgetServiceTest extends TestCase
{
    public function test_small_jpeg_allowed_on_staging_small(): void
    {
        config([
            'asset_processing.worker_profile' => 'staging_small',
            'asset_processing.profiles.staging_small.max_image_mb' => 75,
        ]);

        $asset = new Asset([
            'original_filename' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 10 * 1024 * 1024,
        ]);
        $version = new AssetVersion([
            'mime_type' => 'image/jpeg',
            'file_size' => 10 * 1024 * 1024,
        ]);

        $svc = new AssetProcessingBudgetService;
        $d = $svc->classify($asset, $version);

        $this->assertSame(ProcessingBudgetDecision::ALLOWED, $d->kind);
        $this->assertTrue($svc->canGenerateThumbnails($asset, $version));
    }

    public function test_oversized_psd_deferred_on_staging_small(): void
    {
        config([
            'asset_processing.worker_profile' => 'staging_small',
            'asset_processing.profiles.staging_small.max_psd_mb' => 250,
            'asset_processing.profiles.heavy.max_psd_mb' => 1500,
        ]);

        $bytes = 1024 * 1024 * 1024;
        $asset = new Asset([
            'original_filename' => 'huge.psd',
            'mime_type' => 'image/vnd.adobe.photoshop',
            'size_bytes' => $bytes,
        ]);
        $version = new AssetVersion([
            'mime_type' => 'image/vnd.adobe.photoshop',
            'file_size' => $bytes,
        ]);

        $svc = new AssetProcessingBudgetService;
        $d = $svc->classify($asset, $version);

        $this->assertSame(ProcessingBudgetDecision::DEFER_TO_HEAVY_WORKER, $d->kind);
        $this->assertSame('deferred_to_heavy_worker', $d->failureCode());
        $this->assertFalse($svc->canRunPsdPipeline($asset, $version));
    }

    public function test_same_psd_allowed_on_heavy_profile(): void
    {
        config([
            'asset_processing.worker_profile' => 'heavy',
            'asset_processing.profiles.heavy.max_psd_mb' => 1500,
        ]);

        $bytes = 1024 * 1024 * 1024;
        $asset = new Asset([
            'original_filename' => 'huge.psd',
            'mime_type' => 'image/vnd.adobe.photoshop',
            'size_bytes' => $bytes,
        ]);
        $version = new AssetVersion([
            'mime_type' => 'image/vnd.adobe.photoshop',
            'file_size' => $bytes,
        ]);

        $svc = new AssetProcessingBudgetService;
        $d = $svc->classify($asset, $version);

        $this->assertSame(ProcessingBudgetDecision::ALLOWED, $d->kind);
        $this->assertTrue($svc->canRunPsdPipeline($asset, $version));
    }

    public function test_pixel_limit_exceeded(): void
    {
        config([
            'asset_processing.worker_profile' => 'staging_small',
            'asset_processing.profiles.staging_small.max_pixels' => 1000,
            'asset_processing.profiles.staging_small.max_image_mb' => 500,
        ]);

        $asset = new Asset([
            'original_filename' => 'big.tif',
            'mime_type' => 'image/tiff',
            'width' => 100,
            'height' => 100,
            'size_bytes' => 1024,
        ]);
        $version = new AssetVersion([
            'mime_type' => 'image/tiff',
            'file_size' => 1024,
            'width' => 100,
            'height' => 100,
        ]);

        $svc = new AssetProcessingBudgetService;
        $d = $svc->classify($asset, $version);

        $this->assertSame(ProcessingBudgetDecision::FAIL_PIXEL_LIMIT_EXCEEDED, $d->kind);
        $this->assertSame('pixel_limit_exceeded', $d->failureCode());
    }

    public function test_heavy_queue_plan_when_defer_and_config_enabled(): void
    {
        config([
            'asset_processing.worker_profile' => 'staging_small',
            'asset_processing.profiles.staging_small.max_psd_mb' => 250,
            'asset_processing.profiles.heavy.max_psd_mb' => 1500,
            'asset_processing.defer_heavy_to_queue' => true,
            'queue.images_heavy_queue' => 'images-heavy',
            'queue.images_psd_queue' => '',
        ]);

        $bytes = 1024 * 1024 * 1024;
        $asset = new Asset([
            'original_filename' => 'huge.psd',
            'mime_type' => 'image/vnd.adobe.photoshop',
            'size_bytes' => $bytes,
        ]);
        $version = new AssetVersion([
            'mime_type' => 'image/vnd.adobe.photoshop',
            'file_size' => $bytes,
        ]);

        $svc = new AssetProcessingBudgetService;
        $d = $svc->classify($asset, $version);
        $plan = $svc->heavyQueueRedispatchPlan($asset, $version, $d, $bytes, 'image/vnd.adobe.photoshop');

        $this->assertTrue($plan['should_dispatch']);
        $this->assertNotNull($plan['target_queue']);
    }
}
