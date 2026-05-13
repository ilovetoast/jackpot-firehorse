<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\ThumbnailStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesActivatedTenantBrandAdmin;
use Tests\TestCase;

final class Preview3dTelemetryTest extends TestCase
{
    use CreatesActivatedTenantBrandAdmin;
    use RefreshDatabase;

    public function test_preview_3d_telemetry_accepts_valid_kind(): void
    {
        [$tenant, $brand, $user] = $this->createActivatedTenantBrandAdmin(
            ['name' => 'Telemetry Co', 'slug' => 'telemetry-'.Str::random(6), 'manual_plan_override' => 'enterprise'],
            ['email' => 'p3d-tel@example.test', 'first_name' => 'T', 'last_name' => 'U']
        );

        $asset = $this->createGlbAsset($tenant, $brand);

        $response = $this->actingAsTenantBrand($user, $tenant, $brand)
            ->postJson(route('assets.preview-3d.telemetry', ['asset' => $asset->id]), [
                'kind' => 'model_viewer_error',
            ]);

        $response->assertOk();
        $response->assertJson(['ok' => true]);
    }

    public function test_preview_3d_telemetry_accepts_optional_origins(): void
    {
        [$tenant, $brand, $user] = $this->createActivatedTenantBrandAdmin(
            ['name' => 'Telemetry Co 2', 'slug' => 'telemetry-'.Str::random(6), 'manual_plan_override' => 'enterprise'],
            ['email' => 'p3d-tel2@example.test', 'first_name' => 'T', 'last_name' => 'U']
        );

        $asset = $this->createGlbAsset($tenant, $brand);

        $response = $this->actingAsTenantBrand($user, $tenant, $brand)
            ->postJson(route('assets.preview-3d.telemetry', ['asset' => $asset->id]), [
                'kind' => 'model_viewer_error',
                'page_origin' => 'https://staging-jackpot.velvetysoft.com',
                'model_origin' => 'https://cdn-staging-jackpot.velvetysoft.com',
            ]);

        $response->assertOk();
        $response->assertJson(['ok' => true]);
    }

    protected function createGlbAsset(Tenant $tenant, Brand $brand): Asset
    {
        $bucket = StorageBucket::create([
            'tenant_id' => $tenant->id,
            'name' => 'b-'.$tenant->id,
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
        $upload = UploadSession::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'storage_bucket_id' => $bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        return Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => null,
            'upload_session_id' => $upload->id,
            'storage_bucket_id' => $bucket->id,
            'title' => 'GLB',
            'original_filename' => 'm.glb',
            'mime_type' => 'model/gltf-binary',
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'storage_root_path' => 'tenants/'.$tenant->uuid.'/assets/'.Str::uuid().'/v1/m.glb',
            'size_bytes' => 1024,
            'thumbnail_status' => ThumbnailStatus::COMPLETED,
            'metadata' => [],
        ]);
    }
}
