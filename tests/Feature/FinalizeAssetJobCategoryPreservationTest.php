<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\ThumbnailStatus;
use App\Jobs\FinalizeAssetJob;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\Brand;
use App\Models\Category;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * CRITICAL: Ensures category_id and metadata are NEVER wiped when FinalizeAssetJob
 * syncs from version. Version metadata may lack or have null for asset-scoped fields;
 * these must be preserved from the asset.
 */
class FinalizeAssetJobCategoryPreservationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected Category $category;
    protected User $user;
    protected StorageBucket $bucket;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->tenant = Tenant::create(['name' => 'Test', 'slug' => 'test', 'manual_plan_override' => 'pro']);
        $this->brand = Brand::create(['tenant_id' => $this->tenant->id, 'name' => 'Brand', 'slug' => 'brand']);
        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Logos',
            'slug' => 'logos',
            'asset_type' => \App\Enums\AssetType::ASSET,
            'is_system' => false,
        ]);
        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);
        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'test-bucket',
            'status' => \App\Enums\StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
    }

    /**
     * CRITICAL: When version metadata lacks category_id (or has null), FinalizeAssetJob
     * must preserve the asset's category_id. This prevents assets from disappearing from the grid.
     */
    public function test_finalize_asset_job_preserves_category_id_when_version_metadata_lacks_it(): void
    {
        $uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => \App\Enums\UploadStatus::COMPLETED,
            'type' => \App\Enums\UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $asset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $uploadSession->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => AssetStatus::VISIBLE,
            'type' => \App\Enums\AssetType::ASSET,
            'title' => 'Test Asset',
            'original_filename' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_root_path' => 'assets/' . \Illuminate\Support\Str::uuid() . '/v1/original.jpg',
            'metadata' => [
                'category_id' => $this->category->id,
                'metadata_extracted' => true,
                'preview_generated' => true,
            ],
            'thumbnail_status' => ThumbnailStatus::COMPLETED,
            'published_at' => now(),
            'published_by_id' => $this->user->id,
        ]);

        $version = AssetVersion::create([
            'id' => \Illuminate\Support\Str::uuid(),
            'asset_id' => $asset->id,
            'version_number' => 1,
            'file_path' => $asset->storage_root_path,
            'file_size' => 1024,
            'mime_type' => 'image/jpeg',
            'width' => 800,
            'height' => 600,
            'checksum' => 'test-checksum-' . $asset->id,
            'pipeline_status' => 'complete',
            'is_current' => true,
            'metadata' => [
                'thumbnails' => ['thumb' => ['path' => 'assets/foo/thumb.jpg']],
                'thumbnail_dimensions' => [],
                'category_id' => null,
                'metadata_extracted' => null,
                'preview_generated' => null,
            ],
        ]);

        $asset->update(['storage_root_path' => $version->file_path]);

        $job = new FinalizeAssetJob($asset->id);
        $job->handle();

        $asset->refresh();
        $this->assertSame(
            (int) $this->category->id,
            (int) ($asset->metadata['category_id'] ?? 0),
            'category_id must be preserved when version metadata has null'
        );
        $this->assertTrue($asset->metadata['metadata_extracted'] ?? false, 'metadata_extracted must be preserved');
        $this->assertTrue($asset->metadata['preview_generated'] ?? false, 'preview_generated must be preserved');
    }

    /**
     * When version metadata explicitly has category_id null, asset's value must win.
     */
    public function test_finalize_asset_job_preserves_category_id_when_version_has_null(): void
    {
        $uploadSession = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => \App\Enums\UploadStatus::COMPLETED,
            'type' => \App\Enums\UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $asset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $uploadSession->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => AssetStatus::VISIBLE,
            'type' => \App\Enums\AssetType::ASSET,
            'title' => 'Test Asset',
            'original_filename' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_root_path' => 'assets/' . \Illuminate\Support\Str::uuid() . '/v1/original.jpg',
            'metadata' => [
                'category_id' => $this->category->id,
                'metadata_extracted' => true,
                'preview_generated' => true,
            ],
            'thumbnail_status' => ThumbnailStatus::COMPLETED,
            'published_at' => now(),
            'published_by_id' => $this->user->id,
        ]);

        $version = AssetVersion::create([
            'id' => \Illuminate\Support\Str::uuid(),
            'asset_id' => $asset->id,
            'version_number' => 1,
            'file_path' => $asset->storage_root_path,
            'file_size' => 1024,
            'mime_type' => 'image/jpeg',
            'width' => 800,
            'height' => 600,
            'checksum' => 'test-checksum-' . $asset->id,
            'pipeline_status' => 'complete',
            'is_current' => true,
            'metadata' => [
                'thumbnails' => ['thumb' => ['path' => 'assets/foo/thumb.jpg']],
                'category_id' => null,
                'metadata_extracted' => false,
                'preview_generated' => false,
            ],
        ]);

        $job = new FinalizeAssetJob($asset->id);
        $job->handle();

        $asset->refresh();
        $this->assertSame((int) $this->category->id, (int) ($asset->metadata['category_id'] ?? 0));
        $this->assertTrue($asset->metadata['metadata_extracted'] ?? false);
        $this->assertTrue($asset->metadata['preview_generated'] ?? false);
    }
}
