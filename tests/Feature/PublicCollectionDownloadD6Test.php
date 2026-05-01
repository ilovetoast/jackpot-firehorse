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
use Aws\Command;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Mockery;
use Tests\TestCase;

/**
 * Phase D6 — Public Collection Downloads (on-the-fly ZIP; no Download record)
 *
 * Tests:
 * - Public collection POST redirects to signed zip URL; no Download stored
 * - Private collection cannot create download (404)
 * - GET signed zip URL streams ZIP (only collection assets)
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

    public function test_public_collection_redirects_to_signed_zip_url_and_creates_no_download(): void
    {
        $mockS3 = Mockery::mock(S3Client::class);
        $mockS3->shouldReceive('doesObjectExist')->byDefault()->andReturnUsing(function ($bucket, $key) {
            return ! str_contains((string) $key, 'collection-download.zip');
        });
        $mockS3->shouldReceive('getObject')->byDefault()->andReturn(['Body' => Utils::streamFor('fake-asset-bytes')]);
        $mockS3->shouldReceive('putObject')->byDefault()->andReturn([]);
        $mockS3->shouldReceive('getCommand')->byDefault()->andReturnUsing(static fn (string $name, array $args = []) => new Command($name, $args));
        $mockS3->shouldReceive('createPresignedRequest')->byDefault()->andReturnUsing(
            static fn () => new Request('GET', 'https://example.test/signed-public-collection.zip')
        );
        $this->app->instance(S3Client::class, $mockS3);

        $this->tenant->update(['manual_plan_override' => 'enterprise']);

        $collection = Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Press Kit',
            'slug' => 'press-kit',
            'visibility' => 'brand',
            'is_public' => true,
            'public_share_token' => 'dltesttokenpress01',
            'public_password_hash' => Hash::make('secret'),
            'public_password_set_at' => now(),
        ]);
        $asset = $this->createAsset();
        $collection->assets()->attach($asset->id);

        $response = $this->withSession([$collection->sessionUnlockKey() => true])
            ->postJson(route('public.collections.download', [
                'brand_slug' => $this->brand->slug,
                'collection_slug' => $collection->slug,
            ]));

        $response->assertOk();
        $this->assertNotEmpty($response->json('zip_url'));

        $this->assertNull(Download::query()->where('source', DownloadSource::PUBLIC_COLLECTION)->first());
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

    public function test_signed_zip_url_streams_collection_zip(): void
    {
        $this->tenant->update(['manual_plan_override' => 'enterprise']);

        $collection = Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Kit',
            'slug' => 'kit',
            'visibility' => 'brand',
            'is_public' => true,
            'public_share_token' => 'dltesttokenkit01',
            'public_password_hash' => Hash::make('secret'),
            'public_password_set_at' => now(),
        ]);
        $a1 = $this->createAsset(['title' => 'A1']);
        $a2 = $this->createAsset(['title' => 'A2']);
        $collection->assets()->attach([$a1->id, $a2->id]);

        $mockS3 = Mockery::mock(S3Client::class);
        $mockS3->shouldReceive('doesObjectExist')->andReturn(true);
        $mockS3->shouldReceive('getObject')->andReturnUsing(function (array $args) {
            return ['Body' => \GuzzleHttp\Psr7\Utils::streamFor('fake-asset-content')];
        });
        $this->app->instance(S3Client::class, $mockS3);

        $zipUrl = URL::temporarySignedRoute(
            'public.collections.zip',
            now()->addMinutes(15),
            ['brand_slug' => $this->brand->slug, 'collection_slug' => $collection->slug]
        );

        $response = $this->withSession([$collection->sessionUnlockKey() => true])->get($zipUrl);

        $response->assertOk();
        $response->assertHeader('content-type', 'application/zip');
        $this->assertNull(Download::query()->where('source', DownloadSource::PUBLIC_COLLECTION)->first());
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
            'public_share_token' => 'dltesttokenfree01',
            'public_password_hash' => Hash::make('secret'),
            'public_password_set_at' => now(),
        ]);
        $asset = $this->createAsset();
        $collection->assets()->attach($asset->id);

        $response = $this->withSession([$collection->sessionUnlockKey() => true])
            ->post(route('public.collections.download', [
                'brand_slug' => $this->brand->slug,
                'collection_slug' => $collection->slug,
            ]), ['_token' => csrf_token()]);

        $response->assertStatus(404);
        $this->assertNull(Download::query()->where('source', DownloadSource::PUBLIC_COLLECTION)->first());
    }

    public function test_public_zip_rejects_asset_id_outside_collection(): void
    {
        $this->tenant->update(['manual_plan_override' => 'enterprise']);

        $collection = Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Scoped',
            'slug' => 'scoped',
            'visibility' => 'brand',
            'is_public' => true,
            'public_share_token' => 'dltesttokenscope1',
            'public_password_hash' => Hash::make('secret'),
            'public_password_set_at' => now(),
        ]);
        $in = $this->createAsset(['title' => 'In']);
        $out = $this->createAsset(['title' => 'Out']);
        $collection->assets()->attach($in->id);

        $this->withSession([$collection->sessionUnlockKey() => true])
            ->postJson(route('public.collections.download', [
                'brand_slug' => $this->brand->slug,
                'collection_slug' => $collection->slug,
            ]), ['asset_ids' => [(string) $in->id, (string) $out->id]])
            ->assertStatus(422);
    }

    public function test_public_zip_blocked_when_public_downloads_disabled(): void
    {
        $this->tenant->update(['manual_plan_override' => 'enterprise']);

        $collection = Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'No zip',
            'slug' => 'no-zip',
            'visibility' => 'brand',
            'is_public' => true,
            'public_share_token' => 'dltesttokennozip1',
            'public_password_hash' => Hash::make('secret'),
            'public_password_set_at' => now(),
            'public_downloads_enabled' => false,
        ]);
        $asset = $this->createAsset();
        $collection->assets()->attach($asset->id);

        $this->withSession([$collection->sessionUnlockKey() => true])
            ->postJson(route('public.collections.download', [
                'brand_slug' => $this->brand->slug,
                'collection_slug' => $collection->slug,
            ]))
            ->assertStatus(404);
    }
}
