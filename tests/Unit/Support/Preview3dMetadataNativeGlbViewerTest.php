<?php

namespace Tests\Unit\Support;

use App\Models\Asset;
use App\Models\AssetVersion;
use App\Support\Preview3dMetadata;
use Tests\TestCase;

class Preview3dMetadataNativeGlbViewerTest extends TestCase
{
    public function test_safe_native_glb_viewer_prefers_version_file_path(): void
    {
        $asset = new Asset([
            'storage_root_path' => 'tenants/t1/assets/a1/v1/wrong.bin',
        ]);
        $version = new AssetVersion([
            'file_path' => 'tenants/t1/assets/a1/v1/original.glb',
        ]);

        $key = Preview3dMetadata::safeNativeGlbViewerStorageKey($asset, $version);
        $this->assertSame('tenants/t1/assets/a1/v1/original.glb', $key);
    }

    public function test_safe_native_glb_viewer_falls_back_to_storage_root_path(): void
    {
        $asset = new Asset([
            'storage_root_path' => 'tenants/t1/assets/a1/v1/original.glb',
        ]);

        $key = Preview3dMetadata::safeNativeGlbViewerStorageKey($asset, null);
        $this->assertSame('tenants/t1/assets/a1/v1/original.glb', $key);
    }

    public function test_safe_native_glb_viewer_rejects_non_glb_extension(): void
    {
        $asset = new Asset([
            'storage_root_path' => 'tenants/t1/assets/a1/v1/original.stl',
        ]);

        $this->assertNull(Preview3dMetadata::safeNativeGlbViewerStorageKey($asset, null));
    }

    public function test_safe_native_glb_viewer_rejects_urls(): void
    {
        $asset = new Asset([
            'storage_root_path' => 'https://cdn.example.com/x.glb',
        ]);
        $this->assertNull(Preview3dMetadata::safeNativeGlbViewerStorageKey($asset, null));

        $asset2 = new Asset([
            'storage_root_path' => 's3://bucket/key.glb',
        ]);
        $this->assertNull(Preview3dMetadata::safeNativeGlbViewerStorageKey($asset2, null));
    }

    public function test_initial_from_inspection_sets_viewer_path_only_when_key_passed(): void
    {
        $caps = ['conversion_required' => false];
        $key = 'tenants/t1/assets/a1/v1/original.glb';

        $with = Preview3dMetadata::initialFromInspection('glb', 100, $caps, $key);
        $this->assertSame($key, $with['viewer_path']);

        $without = Preview3dMetadata::initialFromInspection('stl', 50, $caps, null);
        $this->assertNull($without['viewer_path']);

        $ignoredWrongExt = Preview3dMetadata::initialFromInspection('stl', 50, $caps, $key);
        $this->assertNull($ignoredWrongExt['viewer_path']);
    }

    public function test_merge_poster_stub_preserves_existing_native_glb_viewer_path(): void
    {
        $existing = Preview3dMetadata::merge([], [
            'viewer_path' => 'tenants/x/assets/y/v1/original.glb',
            'status' => Preview3dMetadata::STATUS_PENDING,
        ]);
        $afterPoster = Preview3dMetadata::merge($existing, [
            'status' => Preview3dMetadata::STATUS_READY,
            'poster_path' => 'tenants/x/assets/y/v1/previews/p.webp',
            'thumbnail_path' => 'tenants/x/assets/y/v1/previews/t.webp',
            'viewer_path' => 'tenants/x/assets/y/v1/original.glb',
        ]);
        $this->assertSame('tenants/x/assets/y/v1/original.glb', $afterPoster['viewer_path']);
    }
}
