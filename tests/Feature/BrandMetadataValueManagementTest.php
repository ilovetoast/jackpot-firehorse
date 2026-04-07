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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BrandMetadataValueManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_purge_deletes_matching_approved_rows_for_tenant_field(): void
    {
        $tenant = Tenant::create(['name' => 'Val Purge Co', 'slug' => 'val-purge-co']);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'Val Purge Brand',
            'slug' => 'val-purge-brand',
        ]);
        $user = User::create([
            'email' => 'val-purge@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'V',
            'last_name' => 'P',
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'admin']);
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $bucket = StorageBucket::create([
            'tenant_id' => $tenant->id,
            'name' => 'val-purge-buck',
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
        $category = Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'name' => 'Cat',
            'slug' => 'val-purge-cat',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
        ]);
        $asset = Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'upload_session_id' => $session->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => 'Purge me',
            'original_filename' => 'x.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 100,
            'storage_bucket_id' => $bucket->id,
            'storage_root_path' => 'a/x.jpg',
            'metadata' => ['category_id' => $category->id],
        ]);

        $fieldId = DB::table('metadata_fields')->insertGetId([
            'key' => 'custom__val_purge_test',
            'system_label' => 'Purge test field',
            'type' => 'text',
            'applies_to' => 'all',
            'scope' => 'tenant',
            'tenant_id' => $tenant->id,
            'is_active' => true,
            'is_filterable' => false,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'group_key' => 'custom',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $valueJson = json_encode('leggings');
        $now = now();
        DB::table('asset_metadata')->insert([
            'asset_id' => $asset->id,
            'metadata_field_id' => $fieldId,
            'asset_version_id' => null,
            'value_json' => $valueJson,
            'source' => 'user',
            'confidence' => 1.0,
            'producer' => 'user',
            'approved_at' => $now,
            'approved_by' => $user->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->postJson("/app/api/brands/{$brand->id}/metadata-values/purge", [
                'field_key' => 'custom__val_purge_test',
                'value_json' => $valueJson,
            ])
            ->assertOk()
            ->assertJsonPath('rows_deleted', 1);

        $this->assertSame(
            0,
            (int) DB::table('asset_metadata')->where('metadata_field_id', $fieldId)->count()
        );
    }

    public function test_purge_rejects_system_scope_field(): void
    {
        $tenant = Tenant::create(['name' => 'Sys Reject Co', 'slug' => 'sys-reject-co']);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'Sys Reject Brand',
            'slug' => 'sys-reject-brand',
        ]);
        $user = User::create([
            'email' => 'sys-reject@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'S',
            'last_name' => 'R',
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'admin']);
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $key = 'zzz_sys_reject_'.substr(sha1((string) microtime(true)), 0, 12);
        DB::table('metadata_fields')->insertGetId([
            'key' => $key,
            'system_label' => 'System reject test',
            'type' => 'text',
            'applies_to' => 'all',
            'scope' => 'system',
            'tenant_id' => null,
            'is_filterable' => false,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'group_key' => 'general',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->postJson("/app/api/brands/{$brand->id}/metadata-values/purge", [
                'field_key' => $key,
                'value_json' => json_encode('x'),
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'System fields are not managed from this screen']);
    }
}
