<?php

namespace Tests\Unit\Assets\Metadata;

use App\Assets\Metadata\EmbeddedMetadataIndexBuilder;
use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\AssetMetadataIndexEntry;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmbeddedMetadataIndexBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_keys_are_not_indexed(): void
    {
        $asset = $this->makeAsset();
        $builder = app(EmbeddedMetadataIndexBuilder::class);

        $builder->rebuild($asset, [
            'exif' => [
                'Make' => 'Canon',
                'UnknownTag' => 'secret',
            ],
        ]);

        $this->assertDatabaseHas('asset_metadata_index', [
            'asset_id' => $asset->id,
            'normalized_key' => 'camera_make',
        ]);
        $this->assertDatabaseMissing('asset_metadata_index', [
            'asset_id' => $asset->id,
            'key' => 'UnknownTag',
        ]);
    }

    public function test_keyword_array_creates_multiple_rows(): void
    {
        $asset = $this->makeAsset();
        $builder = app(EmbeddedMetadataIndexBuilder::class);

        $builder->rebuild($asset, [
            'iptc' => [
                'Keywords' => ['alpha', 'beta'],
            ],
        ]);

        $this->assertSame(2, AssetMetadataIndexEntry::where('asset_id', $asset->id)->where('normalized_key', 'iptc_keyword')->count());
    }

    public function test_rebuild_with_empty_extracted_namespaces_clears_prior_index_rows(): void
    {
        $asset = $this->makeAsset();
        AssetMetadataIndexEntry::create([
            'asset_id' => $asset->id,
            'namespace' => 'video',
            'key' => 'format.artist',
            'normalized_key' => 'legacy_test',
            'value_type' => 'string',
            'value_string' => 'OldFfprobe',
            'search_text' => 'oldffprobe',
            'is_filterable' => false,
            'is_visible' => false,
            'source_priority' => 100,
        ]);

        $builder = app(EmbeddedMetadataIndexBuilder::class);
        $builder->rebuild($asset, [
            'exif' => [],
            'video' => [],
            'other' => ['video_extract' => 'ffprobe_unavailable'],
        ]);

        $this->assertDatabaseMissing('asset_metadata_index', [
            'asset_id' => $asset->id,
            'normalized_key' => 'legacy_test',
        ]);
    }

    public function test_rebuild_is_idempotent(): void
    {
        $asset = $this->makeAsset();
        $builder = app(EmbeddedMetadataIndexBuilder::class);

        $payload = ['exif' => ['Make' => 'Nikon']];
        $builder->rebuild($asset, $payload);
        $first = AssetMetadataIndexEntry::where('asset_id', $asset->id)->count();

        $builder->rebuild($asset, $payload);
        $second = AssetMetadataIndexEntry::where('asset_id', $asset->id)->count();

        $this->assertSame($first, $second);
    }

    protected function makeAsset(): Asset
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

        return Asset::create([
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
        ]);
    }
}
