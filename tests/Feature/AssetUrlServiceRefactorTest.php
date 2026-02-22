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
use App\Models\Collection;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AssetUrlServiceRefactorTest extends TestCase
{
    use RefreshDatabase;

    protected ?string $testKeyPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testKeyPath = $this->createTestPrivateKey();
        config([
            'cloudfront.domain' => 'cdn.test',
            'cloudfront.key_pair_id' => 'test-key',
            'cloudfront.private_key_path' => $this->testKeyPath,
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->testKeyPath && file_exists($this->testKeyPath)) {
            @unlink($this->testKeyPath);
        }

        parent::tearDown();
    }

    public function test_admin_can_view_thumbnail_across_tenants(): void
    {
        [$tenantA, $brandA, $bucketA] = $this->createTenantBrandAndBucket('Tenant A', 'tenant-a');
        [$tenantB, $brandB, $bucketB] = $this->createTenantBrandAndBucket('Tenant B', 'tenant-b');

        $assetA = $this->createAsset($tenantA, $brandA, $bucketA, ['title' => 'Asset A']);
        $assetB = $this->createAsset($tenantB, $brandB, $bucketB, ['title' => 'Asset B']);

        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $admin = User::create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Site',
            'last_name' => 'Admin',
        ]);
        $admin->assignRole('site_admin');

        $response = $this->actingAs($admin)->get('/app/admin/assets');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Assets/Index')
            ->where('assets', function ($assets) use ($assetA, $assetB) {
                if (! is_array($assets)) {
                    return false;
                }

                $byId = collect($assets)->keyBy('id');
                $urlA = (string) ($byId[$assetA->id]['thumbnail_url'] ?? '');
                $urlB = (string) ($byId[$assetB->id]['thumbnail_url'] ?? '');

                return $urlA !== ''
                    && $urlB !== ''
                    && str_contains($urlA, 'Expires=')
                    && str_contains($urlA, 'Signature=')
                    && str_contains($urlB, 'Expires=')
                    && str_contains($urlB, 'Signature=');
            })
        );
    }

    public function test_public_collection_shows_thumbnails(): void
    {
        [$tenant, $brand, $bucket] = $this->createTenantBrandAndBucket('Public Tenant', 'public-tenant');
        $asset = $this->createAsset($tenant, $brand, $bucket, ['title' => 'Public Asset']);

        $collection = Collection::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'name' => 'Public Collection',
            'slug' => 'public-collection',
            'visibility' => 'brand',
            'is_public' => true,
        ]);
        $collection->assets()->attach($asset->id);

        $response = $this->get(route('public.collections.show', [
            'brand_slug' => $brand->slug,
            'collection_slug' => $collection->slug,
        ]));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Public/Collection')
            ->has('assets', 1)
            ->where('assets.0.id', $asset->id)
            ->where('assets.0.thumbnail_url', fn ($url) => is_string($url) && str_contains($url, 'Expires=') && str_contains($url, 'Signature='))
        );
    }

    public function test_public_collection_hides_when_disabled(): void
    {
        [$tenant, $brand] = $this->createTenantBrandAndBucket('Hidden Tenant', 'hidden-tenant');

        $collection = Collection::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'name' => 'Toggle Collection',
            'slug' => 'toggle-collection',
            'visibility' => 'brand',
            'is_public' => true,
        ]);

        $collection->update(['is_public' => false]);

        $response = $this->get(route('public.collections.show', [
            'brand_slug' => $brand->slug,
            'collection_slug' => $collection->slug,
        ]));

        $response->assertStatus(404);
    }

    public function test_public_download_redirects_to_signed_url(): void
    {
        [$tenant, $brand, $bucket] = $this->createTenantBrandAndBucket('Download Tenant', 'download-tenant');
        $asset = $this->createAsset($tenant, $brand, $bucket, ['title' => 'Downloadable']);

        $collection = Collection::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'name' => 'Downloads',
            'slug' => 'downloads',
            'visibility' => 'brand',
            'is_public' => true,
        ]);
        $collection->assets()->attach($asset->id);

        $response = $this->get(route('public.assets.download', ['asset' => $asset->id]));

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('https://cdn.test/', $location);
        $this->assertStringContainsString('Expires=', $location);
        $this->assertStringContainsString('Signature=', $location);
        $this->assertStringContainsString('Key-Pair-Id=', $location);
    }

    public function test_non_public_asset_returns_403_on_public_route(): void
    {
        [$tenant, $brand, $bucket] = $this->createTenantBrandAndBucket('Private Tenant', 'private-tenant');
        $asset = $this->createAsset($tenant, $brand, $bucket, ['title' => 'Private Asset']);

        $collection = Collection::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'name' => 'Private Collection',
            'slug' => 'private-collection',
            'visibility' => 'private',
            'is_public' => false,
        ]);
        $collection->assets()->attach($asset->id);

        $response = $this->get(route('public.assets.download', ['asset' => $asset->id]));

        $response->assertStatus(403);
    }

    /**
     * @return array{0: Tenant, 1: Brand, 2: StorageBucket}
     */
    protected function createTenantBrandAndBucket(string $name, string $slug): array
    {
        $tenant = Tenant::create([
            'name' => $name,
            'slug' => $slug,
            'manual_plan_override' => 'enterprise',
        ]);
        $tenant->refresh();

        $brand = $tenant->brands()->first() ?? Brand::create([
            'tenant_id' => $tenant->id,
            'name' => $name . ' Brand',
            'slug' => $slug . '-brand',
            'is_default' => true,
        ]);

        $bucket = StorageBucket::create([
            'tenant_id' => $tenant->id,
            'name' => $slug . '-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        return [$tenant, $brand, $bucket];
    }

    protected function createAsset(Tenant $tenant, Brand $brand, StorageBucket $bucket, array $overrides = []): Asset
    {
        $upload = UploadSession::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'storage_bucket_id' => $bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $assetUuid = (string) Str::uuid();
        $basePath = 'tenants/' . $tenant->uuid . '/assets/' . $assetUuid . '/v1';

        return Asset::create(array_merge([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'upload_session_id' => $upload->id,
            'storage_bucket_id' => $bucket->id,
            'title' => 'Test Asset',
            'original_filename' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'storage_root_path' => $basePath . '/original.jpg',
            'size_bytes' => 1024,
            'thumbnail_status' => ThumbnailStatus::COMPLETED,
            'metadata' => [
                'thumbnails' => [
                    'thumb' => ['path' => $basePath . '/thumbnails/thumb/thumb.webp'],
                    'medium' => ['path' => $basePath . '/thumbnails/medium/medium.webp'],
                    'large' => ['path' => $basePath . '/thumbnails/large/large.webp'],
                ],
                'thumbnails_generated_at' => now()->toIso8601String(),
            ],
            'published_at' => now(),
        ], $overrides));
    }

    protected function createTestPrivateKey(): string
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($key, $keyContent);
        $path = sys_get_temp_dir() . '/cloudfront-asset-url-test-' . uniqid() . '.pem';
        file_put_contents($path, $keyContent);

        return $path;
    }
}
