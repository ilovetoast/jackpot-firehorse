<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\ThumbnailStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Category;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Processing tray JSON must only list assets uploaded by the authenticated user.
 */
class AssetProcessingTrayScopeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function processing_endpoint_returns_only_current_users_assets(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't-tray']);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'B', 'slug' => 'b-tray']);
        $category = Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'name' => 'Photos',
            'slug' => 'photos-tray',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
        ]);
        $categoryId = (int) $category->id;
        $bucket = StorageBucket::create([
            'tenant_id' => $tenant->id,
            'name' => 'buck',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        $sessionA = UploadSession::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'storage_bucket_id' => $bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);
        $sessionB = UploadSession::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'storage_bucket_id' => $bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $userA = User::create([
            'email' => 'a@tray.test',
            'password' => bcrypt('password'),
            'first_name' => 'A',
            'last_name' => 'User',
        ]);
        $userB = User::create([
            'email' => 'b@tray.test',
            'password' => bcrypt('password'),
            'first_name' => 'B',
            'last_name' => 'User',
        ]);
        $userA->tenants()->attach($tenant->id, ['role' => 'member']);
        $userB->tenants()->attach($tenant->id, ['role' => 'member']);
        $userA->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);
        $userB->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $assetA = Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $userA->id,
            'upload_session_id' => $sessionA->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'From A',
            'original_filename' => 'a.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 100,
            'storage_bucket_id' => $bucket->id,
            'storage_root_path' => 'a/a.jpg',
            'metadata' => ['category_id' => $categoryId],
            'thumbnail_status' => ThumbnailStatus::PROCESSING,
        ]);
        $assetB = Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $userB->id,
            'upload_session_id' => $sessionB->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'From B',
            'original_filename' => 'b.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 100,
            'storage_bucket_id' => $bucket->id,
            'storage_root_path' => 'b/b.jpg',
            'metadata' => ['category_id' => $categoryId],
            'thumbnail_status' => ThumbnailStatus::PROCESSING,
        ]);

        $responseA = $this->actingAs($userA)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->getJson(route('assets.processing'));

        $responseA->assertOk();
        $dataA = $responseA->json('active_jobs');
        $this->assertCount(1, $dataA);
        $this->assertSame($assetA->id, $dataA[0]['id']);

        $responseB = $this->actingAs($userB)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->getJson(route('assets.processing'));

        $responseB->assertOk();
        $dataB = $responseB->json('active_jobs');
        $this->assertCount(1, $dataB);
        $this->assertSame($assetB->id, $dataB[0]['id']);
    }
}
