<?php

namespace Tests\Unit\Assets\Metadata;

use App\Assets\Metadata\EmbeddedMetadataSystemMapper;
use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmbeddedMetadataSystemMapperTest extends TestCase
{
    use RefreshDatabase;

    public function test_fill_if_empty_does_not_overwrite_existing_captured_at(): void
    {
        $asset = $this->makeAsset(['captured_at' => now()->subYear()]);
        $mapper = app(EmbeddedMetadataSystemMapper::class);

        $mapper->apply($asset, [
            'exif' => [
                'DateTimeOriginal' => '2020:01:15 12:00:00',
            ],
        ]);

        $asset->refresh();
        $this->assertTrue($asset->captured_at->isSameDay(now()->subYear()));
    }

    public function test_fill_if_empty_sets_captured_at_when_null(): void
    {
        $asset = $this->makeAsset(['captured_at' => null]);
        $mapper = app(EmbeddedMetadataSystemMapper::class);

        $mapper->apply($asset, [
            'exif' => [
                'DateTimeOriginal' => '2020:01:15 12:00:00',
            ],
        ]);

        $asset->refresh();
        $this->assertNotNull($asset->captured_at);
        $this->assertSame('2020-01-15', $asset->captured_at->format('Y-m-d'));
        $meta = $asset->metadata ?? [];
        $this->assertArrayHasKey('embedded_system_map', $meta);
        $this->assertSame('exif.DateTimeOriginal', $meta['embedded_system_map']['captured_at']['source_fq'] ?? null);
        $this->assertSame('fill_if_empty', $meta['embedded_system_map']['captured_at']['system_map_mode'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function makeAsset(array $extra = []): Asset
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't-'.uniqid()]);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'B', 'slug' => 'b-'.uniqid()]);
        $bucket = StorageBucket::create([
            'tenant_id' => $tenant->id,
            'name' => 'bk',
            'status' => \App\Enums\StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
        $session = UploadSession::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'storage_bucket_id' => $bucket->id,
            'status' => \App\Enums\UploadStatus::COMPLETED,
            'type' => \App\Enums\UploadType::DIRECT,
            'expected_size' => 1,
            'uploaded_size' => 1,
        ]);
        $user = User::create([
            'email' => uniqid().'@e.com',
            'password' => bcrypt('p'),
            'first_name' => 'U',
            'last_name' => 'U',
        ]);
        $user->tenants()->attach($tenant->id);

        return Asset::create(array_merge([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'upload_session_id' => $session->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 't',
            'original_filename' => 'x.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1,
            'storage_bucket_id' => $bucket->id,
            'storage_root_path' => 'a/b.jpg',
            'metadata' => [],
        ], $extra));
    }
}
