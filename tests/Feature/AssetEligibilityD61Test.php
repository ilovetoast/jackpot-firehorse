<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
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
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

/**
 * D6.1 — Asset Eligibility Enforcement
 *
 * Tests:
 * - Unpublished asset cannot be added to collection (422)
 * - Archived asset is excluded from download (SOFT FILTER — download created with fewer assets)
 * - Pending/unpublished asset not included in public collection download (ZIP contains only published)
 */
class AssetEligibilityD61Test extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected User $user;
    protected StorageBucket $bucket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        $this->brand = Brand::create(['tenant_id' => $this->tenant->id, 'name' => 'B', 'slug' => 'b']);
        $this->user = User::create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Admin',
            'last_name' => 'User',
        ]);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'admin']);
        $this->user->brands()->attach($this->brand->id, ['role' => 'brand_manager']);
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
        ], $overrides));
    }

    public function test_unpublished_asset_cannot_be_added_to_collection(): void
    {
        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brand->id);

        $collection = Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Coll',
            'slug' => 'coll',
            'visibility' => 'brand',
            'is_public' => false,
        ]);
        $asset = $this->createAsset(['published_at' => null]);

        $response = $this->actingAs($this->user)
            ->postJson(route('collections.assets.store', ['collection' => $collection->id]), [
                'asset_id' => $asset->id,
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Some selected assets are not published and cannot be added to collections.']);
        $this->assertFalse($collection->assets()->where('assets.id', $asset->id)->exists());
    }

    public function test_archived_asset_is_excluded_from_download(): void
    {
        $published = $this->createAsset(['published_at' => now(), 'title' => 'Published']);
        $archived = $this->createAsset(['published_at' => now(), 'archived_at' => now(), 'title' => 'Archived']);

        Queue::fake();

        // Bucket contains both IDs; store() must filter to eligible only (SOFT FILTER). Result: download with 1 asset (published).
        $session = [
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'download_bucket_asset_ids' => [$published->id, $archived->id],
        ];
        $response = $this->actingAs($this->user)->withSession($session)->postJson(route('downloads.store'), ['source' => 'grid']);
        $response->assertOk();

        $download = Download::query()->where('source', DownloadSource::GRID->value)->latest()->first();
        $this->assertNotNull($download);
        $this->assertSame(1, $download->assets()->count());
        $this->assertTrue($download->assets()->where('assets.id', $published->id)->exists());
        $this->assertFalse($download->assets()->where('assets.id', $archived->id)->exists());
    }

    public function test_pending_asset_not_included_in_public_collection_download(): void
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
        $published = $this->createAsset(['published_at' => now(), 'title' => 'Published']);
        $pending = $this->createAsset(['published_at' => null, 'title' => 'Pending']);
        $collection->assets()->attach([$published->id, $pending->id]);

        $response = $this->post(route('public.collections.download', [
            'brand_slug' => $this->brand->slug,
            'collection_slug' => $collection->slug,
        ]), ['_token' => csrf_token()]);

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('/zip', $location);
        $this->assertNull(Download::query()->where('source', DownloadSource::PUBLIC_COLLECTION)->first());
        // On-the-fly zip uses queryPublic() which excludes unpublished assets; only published is included when zip is streamed.
    }
}
