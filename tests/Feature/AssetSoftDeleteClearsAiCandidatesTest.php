<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AssetSoftDeleteClearsAiCandidatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_soft_deleting_asset_removes_pending_tag_candidates(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't-ai-cand']);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'B', 'slug' => 'b']);
        $bucket = StorageBucket::create([
            'tenant_id' => $tenant->id,
            'name' => 'bucket',
            'status' => \App\Enums\StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
        $uploadSession = UploadSession::create([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'storage_bucket_id' => $bucket->id,
            'status' => \App\Enums\UploadStatus::COMPLETED,
            'type' => \App\Enums\UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $asset = Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'upload_session_id' => $uploadSession->id,
            'storage_bucket_id' => $bucket->id,
            'mime_type' => 'image/jpeg',
            'original_filename' => 'x.jpg',
            'size_bytes' => 1024,
            'storage_root_path' => 'p/x.jpg',
            'metadata' => [],
            'status' => \App\Enums\AssetStatus::VISIBLE,
            'type' => \App\Enums\AssetType::ASSET,
        ]);

        DB::table('asset_tag_candidates')->insert([
            'asset_id' => $asset->id,
            'tag' => 'color',
            'producer' => 'ai',
            'source' => 'ai',
            'confidence' => 0.93,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('asset_tag_candidates', ['asset_id' => $asset->id]);

        $asset->delete();

        $this->assertDatabaseMissing('asset_tag_candidates', ['asset_id' => $asset->id]);
    }
}
