<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Brand;
use App\Models\Category;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * AI Metadata Regeneration Test
 *
 * Tests the admin rerun endpoint for regenerating AI metadata.
 */
class AiMetadataRegenerationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test: Regenerate endpoint requires permission
     */
    public function test_regenerate_endpoint_requires_permission(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $user->tenants()->attach($tenant->id, ['role' => 'viewer']); // No permission

        $asset = $this->createAsset($tenant);

        $response = $this->actingAs($user)
            ->postJson("/app/assets/{$asset->id}/ai-metadata/regenerate");

        $response->assertStatus(403);
    }

    /**
     * Test: Regenerate endpoint checks plan limits
     */
    public function test_regenerate_endpoint_checks_plan_limits(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $user->tenants()->attach($tenant->id, ['role' => 'admin']);

        // Set tenant at plan limit
        DB::table('ai_usage')->insert([
            'tenant_id' => $tenant->id,
            'feature' => 'tagging',
            'usage_date' => now()->toDateString(),
            'call_count' => 100, // At limit (assuming plan limit is 100)
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $asset = $this->createAsset($tenant);

        $response = $this->actingAs($user)
            ->postJson("/app/assets/{$asset->id}/ai-metadata/regenerate");

        $response->assertStatus(403);
        $response->assertJson([
            'error' => 'Plan limit exceeded',
        ]);
    }

    /**
     * Test: Regenerate endpoint dispatches job
     */
    public function test_regenerate_endpoint_dispatches_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $user->tenants()->attach($tenant->id, ['role' => 'admin']);

        $asset = $this->createAsset($tenant);

        $response = $this->actingAs($user)
            ->postJson("/app/assets/{$asset->id}/ai-metadata/regenerate");

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'AI metadata regeneration queued',
        ]);

        Queue::assertPushed(\App\Jobs\AiMetadataGenerationJob::class, function ($job) use ($asset) {
            return $job->assetId === $asset->id && $job->isManualRerun === true;
        });
    }

    /**
     * Helper: Create asset
     */
    protected function createAsset(Tenant $tenant): Asset
    {
        $brand = Brand::firstOrCreate(['tenant_id' => $tenant->id], [
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);

        $storageBucket = StorageBucket::firstOrCreate(['tenant_id' => $tenant->id], [
            'name' => 'test-bucket',
            'status' => \App\Enums\StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        $uploadSession = UploadSession::create([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'storage_bucket_id' => $storageBucket->id,
            'status' => \App\Enums\UploadStatus::COMPLETED,
            'type' => \App\Enums\UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        return Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'upload_session_id' => $uploadSession->id,
            'storage_bucket_id' => $storageBucket->id,
            'mime_type' => 'image/jpeg',
            'original_filename' => 'test.jpg',
            'size_bytes' => 1024,
            'storage_root_path' => 'test/path.jpg',
            'metadata' => [],
            'status' => \App\Enums\AssetStatus::VISIBLE,
            'type' => \App\Enums\AssetType::ASSET,
        ]);
    }
}
