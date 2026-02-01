<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\DownloadAccessMode;
use App\Enums\DownloadSource;
use App\Enums\StorageBucketStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Collection;
use App\Models\Download;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Phase D6 — Public Collection Downloads
 *
 * Tests:
 * - Public collection can create download
 * - Private collection cannot
 * - Download contains only collection assets
 * - Download expires and is cleaned up (D5 applies)
 * - Non-Enterprise plan cannot create public collection downloads (gated)
 */
class PublicCollectionDownloadD6Test extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected StorageBucket $bucket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        $this->brand = Brand::create(['tenant_id' => $this->tenant->id, 'name' => 'B', 'slug' => 'b']);
        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
    }

    protected function createAsset(array $overrides = []): Asset
    {
        $upload = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);
        return Asset::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => null,
            'upload_session_id' => $upload->id,
            'storage_bucket_id' => $this->bucket->id,
            'title' => 'Test Asset',
            'original_filename' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'storage_root_path' => 'test/path.jpg',
            'size_bytes' => 1024,
            'metadata' => ['file_size' => 1024],
            'published_at' => now(), // D6.1: Eligible for collections and downloads
        ], $overrides));
    }

    public function test_public_collection_can_create_download(): void
    {
        $this->tenant->update(['manual_plan_override' => 'enterprise']);

        $collection = Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Press Kit',
            'slug' => 'press-kit',
            'visibility' => 'brand',
            'is_public' => true,
        ]);
        $asset = $this->createAsset();
        $collection->assets()->attach($asset->id);

        Queue::fake();

        $response = $this->post(route('public.collections.download', [
            'brand_slug' => $this->brand->slug,
            'collection_slug' => $collection->slug,
        ]), [
            'name' => 'Press Kit Download',
            'expires_at' => now()->addDays(30)->format('Y-m-d'),
            '_token' => csrf_token(),
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('/d/', $response->headers->get('Location'));

        $download = Download::query()->where('source', DownloadSource::PUBLIC_COLLECTION)->latest()->first();
        $this->assertNotNull($download);
        $this->assertSame($this->tenant->id, $download->tenant_id);
        $this->assertSame($this->brand->id, $download->brand_id);
        $this->assertSame(DownloadAccessMode::PUBLIC, $download->access_mode);
        $this->assertSame('Press Kit Download', $download->title);
        $this->assertSame($collection->id, $download->download_options['collection_id'] ?? null);
    }

    public function test_private_collection_cannot_create_download(): void
    {
        $this->tenant->update(['manual_plan_override' => 'enterprise']);

        $collection = Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Private',
            'slug' => 'private-collection',
            'visibility' => 'private',
            'is_public' => false,
        ]);
        $asset = $this->createAsset();
        $collection->assets()->attach($asset->id);

        $response = $this->post(route('public.collections.download', [
            'brand_slug' => $this->brand->slug,
            'collection_slug' => $collection->slug,
        ]), ['_token' => csrf_token()]);

        $response->assertStatus(404);
        $this->assertNull(Download::query()->where('source', DownloadSource::PUBLIC_COLLECTION)->first());
    }

    public function test_download_contains_only_collection_assets(): void
    {
        $this->tenant->update(['manual_plan_override' => 'enterprise']);

        $collection = Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Kit',
            'slug' => 'kit',
            'visibility' => 'brand',
            'is_public' => true,
        ]);
        $a1 = $this->createAsset(['title' => 'A1']);
        $a2 = $this->createAsset(['title' => 'A2']);
        $collection->assets()->attach([$a1->id, $a2->id]);

        Queue::fake();

        $response = $this->post(route('public.collections.download', [
            'brand_slug' => $this->brand->slug,
            'collection_slug' => $collection->slug,
        ]), ['_token' => csrf_token()]);

        $response->assertRedirect();
        $download = Download::query()->where('source', DownloadSource::PUBLIC_COLLECTION)->latest()->first();
        $this->assertNotNull($download);
        $ids = $download->assets()->pluck('assets.id')->all();
        $this->assertCount(2, $ids);
        $this->assertContains($a1->id, $ids);
        $this->assertContains($a2->id, $ids);
    }

    public function test_download_expires_and_is_cleaned_up(): void
    {
        $this->tenant->update(['manual_plan_override' => 'enterprise']);

        $collection = Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Kit',
            'slug' => 'kit',
            'visibility' => 'brand',
            'is_public' => true,
        ]);
        $asset = $this->createAsset();
        $collection->assets()->attach($asset->id);

        Queue::fake();

        $this->post(route('public.collections.download', [
            'brand_slug' => $this->brand->slug,
            'collection_slug' => $collection->slug,
        ]), ['_token' => csrf_token()]);

        $download = Download::query()->where('source', DownloadSource::PUBLIC_COLLECTION)->latest()->first();
        $this->assertNotNull($download);
        $this->assertNotNull($download->expires_at);
        $this->assertNotNull($download->hard_delete_at);
    }

    public function test_non_enterprise_plan_cannot_create_public_collection_downloads(): void
    {
        $this->tenant->update(['manual_plan_override' => 'free']);

        $collection = Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Public',
            'slug' => 'public-collection',
            'visibility' => 'brand',
            'is_public' => true,
        ]);
        $asset = $this->createAsset();
        $collection->assets()->attach($asset->id);

        $response = $this->post(route('public.collections.download', [
            'brand_slug' => $this->brand->slug,
            'collection_slug' => $collection->slug,
        ]), ['_token' => csrf_token()]);

        $response->assertStatus(404);
        $this->assertNull(Download::query()->where('source', DownloadSource::PUBLIC_COLLECTION)->first());
    }
}
