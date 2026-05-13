<?php

namespace Tests\Unit\Support;

use App\Models\Asset;
use App\Support\AssetVariant;
use App\Support\AuthenticatedCrossOriginDeliveryPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthenticatedCrossOriginDeliveryPolicyTest extends TestCase
{
    public static function glbOriginalCases(): array
    {
        return [
            'mime' => [Asset::make(['mime_type' => 'model/gltf-binary', 'original_filename' => 'x.bin', 'storage_root_path' => 't/a/v1/x.bin']), true],
            'extension' => [Asset::make(['mime_type' => 'application/octet-stream', 'original_filename' => 'M.glb', 'storage_root_path' => 'p']), true],
            'storage_path' => [Asset::make(['mime_type' => '', 'original_filename' => '', 'storage_root_path' => 'tenants/u/a/v1/original.glb']), true],
            'jpeg' => [Asset::make(['mime_type' => 'image/jpeg', 'original_filename' => 'a.jpg', 'storage_root_path' => 't/a.jpg']), false],
        ];
    }

    #[DataProvider('glbOriginalCases')]
    public function test_original_variant_only_for_gltf_binary(Asset $asset, bool $expect): void
    {
        $this->assertSame(
            $expect,
            AuthenticatedCrossOriginDeliveryPolicy::requiresSignedCloudFrontUrl(AssetVariant::ORIGINAL, $asset)
        );
    }

    public function test_preview_3d_glb_and_video_variants(): void
    {
        $asset = Asset::make(['mime_type' => 'image/jpeg']);
        $this->assertTrue(
            AuthenticatedCrossOriginDeliveryPolicy::requiresSignedCloudFrontUrl(AssetVariant::PREVIEW_3D_GLB, $asset)
        );
        $this->assertTrue(
            AuthenticatedCrossOriginDeliveryPolicy::requiresSignedCloudFrontUrl(AssetVariant::VIDEO_WEB, $asset)
        );
        $this->assertTrue(
            AuthenticatedCrossOriginDeliveryPolicy::requiresSignedCloudFrontUrl(AssetVariant::VIDEO_PREVIEW, $asset)
        );
        $this->assertFalse(
            AuthenticatedCrossOriginDeliveryPolicy::requiresSignedCloudFrontUrl(AssetVariant::THUMB_SMALL, $asset)
        );
    }
}
