<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Category;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\BulkMetadataService;
use App\Services\Metadata\AssetMetadataStateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BulkAndDrawerMultiselectTagsMergeTest extends TestCase
{
    use RefreshDatabase;

    public function test_merged_approved_multiselect_unions_all_tag_rows(): void
    {
        $tenant = Tenant::create(['name' => 'T Merge', 'slug' => 't-merge-ms']);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'B', 'slug' => 'b-merge-ms']);
        $category = Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'name' => 'Cat',
            'slug' => 'cat-merge-ms',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
        ]);
        $bucket = StorageBucket::create([
            'tenant_id' => $tenant->id,
            'name' => 'buck-merge',
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
            'email' => 'u@merge-ms.test',
            'password' => bcrypt('password'),
        ]);
        $asset = Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'upload_session_id' => $session->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Pic',
            'original_filename' => 'p.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 100,
            'storage_bucket_id' => $bucket->id,
            'storage_root_path' => 'a/p.jpg',
            'metadata' => ['category_id' => $category->id],
            'analysis_status' => 'complete',
        ]);

        $fieldId = DB::table('metadata_fields')->insertGetId([
            'key' => 'tags',
            'system_label' => 'Tags',
            'type' => 'multiselect',
            'applies_to' => 'all',
            'scope' => 'system',
            'group_key' => 'general',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_internal_only' => false,
            'tenant_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $now = now();
        foreach (['alpha', 'beta'] as $tag) {
            DB::table('asset_metadata')->insert([
                'asset_id' => $asset->id,
                'metadata_field_id' => $fieldId,
                'asset_version_id' => null,
                'value_json' => json_encode($tag),
                'source' => 'user',
                'confidence' => 1.0,
                'producer' => 'user',
                'approved_at' => $now,
                'approved_by' => $user->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $resolver = app(AssetMetadataStateResolver::class);
        $merged = $resolver->mergedApprovedMultiselectValuesForField($asset->fresh(), $fieldId);

        sort($merged);
        $this->assertSame(['alpha', 'beta'], $merged);
    }

    public function test_load_current_metadata_includes_asset_tags_when_no_user_metadata_rows(): void
    {
        $tenant = Tenant::create(['name' => 'T Mirror', 'slug' => 't-mirror-tags']);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'B2', 'slug' => 'b2-mirror-tags']);
        $category = Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'name' => 'Cat2',
            'slug' => 'cat2-mirror-tags',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
        ]);
        $bucket = StorageBucket::create([
            'tenant_id' => $tenant->id,
            'name' => 'buck-mirror',
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
            'email' => 'u2@mirror-tags.test',
            'password' => bcrypt('password'),
        ]);
        $asset = Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'upload_session_id' => $session->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Pic2',
            'original_filename' => 'p2.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 100,
            'storage_bucket_id' => $bucket->id,
            'storage_root_path' => 'a/p2.jpg',
            'metadata' => ['category_id' => $category->id],
            'analysis_status' => 'complete',
        ]);

        DB::table('asset_tags')->insert([
            'asset_id' => $asset->id,
            'tag' => 'from_mirror',
            'source' => 'manual',
            'confidence' => null,
            'created_at' => now(),
        ]);

        $svc = app(BulkMetadataService::class);
        $ref = new \ReflectionMethod(BulkMetadataService::class, 'loadCurrentMetadata');
        $ref->setAccessible(true);
        /** @var array<string, mixed> $meta */
        $meta = $ref->invoke($svc, $asset->fresh());

        $tags = $meta['tags'] ?? [];
        $this->assertIsArray($tags);
        $this->assertContains('from_mirror', $tags);
    }
}
