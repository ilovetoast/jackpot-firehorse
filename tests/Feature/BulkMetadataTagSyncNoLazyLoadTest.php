<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Jobs\DebouncedBrandIntelligenceRescoreJob;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Category;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\BrandIntelligence\BrandIntelligenceScheduleService;
use App\Services\MetadataPersistenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Bulk tag sync (and EBI debounce) must not touch {@see Asset::category()} — lazy loading is disabled
 * outside production. Regression: bulk metadata tag add failed with LazyLoadingViolation on [category].
 */
class BulkMetadataTagSyncNoLazyLoadTest extends TestCase
{
    use RefreshDatabase;

    public function test_schedule_debounced_rescore_after_user_edit_does_not_lazy_load_category(): void
    {
        Queue::fake();
        Cache::flush();

        $tenant = Tenant::create(['name' => 'T Lazy', 'slug' => 't-lazy-bulk']);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'B', 'slug' => 'b-lazy-bulk']);
        $category = Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'name' => 'Cat',
            'slug' => 'cat-lazy-bulk',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
            'settings' => ['ebi_enabled' => true],
        ]);
        $bucket = StorageBucket::create([
            'tenant_id' => $tenant->id,
            'name' => 'buck-lazy',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
        $session = UploadSession::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'storage_bucket_id' => $bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);
        $user = User::create([
            'name' => 'U',
            'email' => 'u@lazy-bulk.test',
            'password' => bcrypt('password'),
        ]);
        $asset = Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'upload_session_id' => $session->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Green',
            'original_filename' => 'g.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 100,
            'storage_bucket_id' => $bucket->id,
            'storage_root_path' => 'a/g.jpg',
            'metadata' => ['category_id' => $category->id],
            'analysis_status' => 'complete',
        ]);

        app(BrandIntelligenceScheduleService::class)->scheduleDebouncedRescoreAfterUserEdit($asset);

        Queue::assertPushed(DebouncedBrandIntelligenceRescoreJob::class);
    }

    public function test_sync_approved_tag_batch_values_mirrors_tag_without_lazy_loading_category(): void
    {
        Queue::fake();
        Cache::flush();

        $tenant = Tenant::create(['name' => 'T2 Lazy', 'slug' => 't2-lazy-bulk']);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'B2', 'slug' => 'b2-lazy-bulk']);
        $category = Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'name' => 'Cat2',
            'slug' => 'cat2-lazy-bulk',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
            'settings' => ['ebi_enabled' => true],
        ]);
        $bucket = StorageBucket::create([
            'tenant_id' => $tenant->id,
            'name' => 'buck2-lazy',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
        $session = UploadSession::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'storage_bucket_id' => $bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);
        $user = User::create([
            'name' => 'U2',
            'email' => 'u2@lazy-bulk.test',
            'password' => bcrypt('password'),
        ]);
        $asset = Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'upload_session_id' => $session->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Green',
            'original_filename' => 'g2.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 100,
            'storage_bucket_id' => $bucket->id,
            'storage_root_path' => 'a/g2.jpg',
            'metadata' => ['category_id' => $category->id],
            'analysis_status' => 'complete',
        ]);

        app(MetadataPersistenceService::class)->syncApprovedTagBatchValues($asset, $tenant, ['forest']);

        $this->assertSame(1, DB::table('asset_tags')->where('asset_id', $asset->id)->count());
        Queue::assertPushed(DebouncedBrandIntelligenceRescoreJob::class);
    }

    public function test_resolve_category_for_tenant_matches_metadata_without_relationship(): void
    {
        $tenant = Tenant::create(['name' => 'T3', 'slug' => 't3-resolve']);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'B3', 'slug' => 'b3-resolve']);
        $category = Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'name' => 'Resolved',
            'slug' => 'resolved-cat',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
        ]);
        $bucket = StorageBucket::create([
            'tenant_id' => $tenant->id,
            'name' => 'buck3',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
        $session = UploadSession::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'storage_bucket_id' => $bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);
        $user = User::create([
            'name' => 'U3',
            'email' => 'u3@resolve.test',
            'password' => bcrypt('password'),
        ]);
        $asset = Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'upload_session_id' => $session->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'X',
            'original_filename' => 'x.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 100,
            'storage_bucket_id' => $bucket->id,
            'storage_root_path' => 'a/x.jpg',
            'metadata' => ['category_id' => $category->id],
        ]);

        $resolved = $asset->resolveCategoryForTenant();
        $this->assertNotNull($resolved);
        $this->assertSame($category->id, $resolved->id);
    }
}
