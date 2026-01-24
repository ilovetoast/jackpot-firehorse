<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Asset Tag API Test
 *
 * Phase J.2.3: Tests for tag UX API endpoints
 */
class AssetTagApiTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected User $user;
    protected Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        // Create tenant
        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        // Create brand
        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);

        // Create user with permissions
        $this->user = User::factory()->create([
            'first_name' => 'Test',
            'last_name' => 'User',
        ]);

        // Attach user to tenant with permissions
        $this->user->tenants()->attach($this->tenant->id, [
            'permissions' => json_encode([
                'assets.view',
                'assets.tags.create',
                'assets.tags.delete'
            ])
        ]);

        // Create storage bucket and upload session
        $storageBucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'test-bucket',
            'status' => \App\Enums\StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        $uploadSession = UploadSession::create([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $storageBucket->id,
            'status' => \App\Enums\UploadStatus::COMPLETED,
            'type' => \App\Enums\UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        // Create asset
        $this->asset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
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

        // Set up app context
        app()->instance('tenant', $this->tenant);
        app()->instance('brand', $this->brand);
        Auth::login($this->user);
    }

    /**
     * Test: Get tags for an asset
     */
    public function test_get_tags_for_asset(): void
    {
        // Create some tags
        DB::table('asset_tags')->insert([
            [
                'asset_id' => $this->asset->id,
                'tag' => 'manual-tag',
                'source' => 'manual',
                'created_at' => now(),
            ],
            [
                'asset_id' => $this->asset->id,
                'tag' => 'ai-tag',
                'source' => 'ai',
                'confidence' => 0.95,
                'created_at' => now(),
            ],
        ]);

        $response = $this->getJson("/api/assets/{$this->asset->id}/tags");

        $response->assertOk();
        $response->assertJsonStructure([
            'tags' => [
                '*' => ['id', 'tag', 'source', 'confidence', 'created_at']
            ],
            'total'
        ]);

        $tags = $response->json('tags');
        $this->assertCount(2, $tags);
        $this->assertEquals('manual-tag', $tags[0]['tag']);
        $this->assertEquals('ai-tag', $tags[1]['tag']);
    }

    /**
     * Test: Create a new tag
     */
    public function test_create_new_tag(): void
    {
        $response = $this->postJson("/api/assets/{$this->asset->id}/tags", [
            'tag' => 'New Test Tag!'
        ]);

        $response->assertCreated();
        $response->assertJsonStructure([
            'message',
            'tag' => ['id', 'tag', 'source', 'confidence', 'created_at'],
            'canonical_tag',
            'original_tag'
        ]);

        // Should be normalized
        $this->assertEquals('new-test-tag', $response->json('tag.tag'));
        $this->assertEquals('manual', $response->json('tag.source'));

        // Verify in database
        $this->assertDatabaseHas('asset_tags', [
            'asset_id' => $this->asset->id,
            'tag' => 'new-test-tag',
            'source' => 'manual',
        ]);
    }

    /**
     * Test: Cannot create duplicate tag
     */
    public function test_cannot_create_duplicate_tag(): void
    {
        // Create existing tag
        DB::table('asset_tags')->insert([
            'asset_id' => $this->asset->id,
            'tag' => 'existing-tag',
            'source' => 'manual',
            'created_at' => now(),
        ]);

        $response = $this->postJson("/api/assets/{$this->asset->id}/tags", [
            'tag' => 'Existing Tag!'  // Different form but normalizes to same
        ]);

        $response->assertStatus(409);
        $response->assertJson([
            'message' => 'Tag already exists',
            'canonical_tag' => 'existing-tag',
        ]);
    }

    /**
     * Test: Remove a tag
     */
    public function test_remove_tag(): void
    {
        // Create tag
        $tagId = DB::table('asset_tags')->insertGetId([
            'asset_id' => $this->asset->id,
            'tag' => 'removable-tag',
            'source' => 'manual',
            'created_at' => now(),
        ]);

        $response = $this->deleteJson("/api/assets/{$this->asset->id}/tags/{$tagId}");

        $response->assertOk();
        $response->assertJson([
            'message' => 'Tag removed successfully',
        ]);

        // Verify removed from database
        $this->assertDatabaseMissing('asset_tags', [
            'id' => $tagId,
            'asset_id' => $this->asset->id,
        ]);
    }

    /**
     * Test: Autocomplete suggestions
     */
    public function test_autocomplete_suggestions(): void
    {
        // Create some existing tags across tenant
        $otherAsset = Asset::factory()->create(['tenant_id' => $this->tenant->id]);

        DB::table('asset_tags')->insert([
            [
                'asset_id' => $this->asset->id,
                'tag' => 'photography',
                'source' => 'manual',
                'created_at' => now(),
            ],
            [
                'asset_id' => $otherAsset->id,
                'tag' => 'photo-editing',
                'source' => 'manual',
                'created_at' => now(),
            ],
            [
                'asset_id' => $otherAsset->id,
                'tag' => 'portrait',
                'source' => 'ai',
                'created_at' => now(),
            ],
        ]);

        $response = $this->getJson("/api/assets/{$this->asset->id}/tags/autocomplete?q=photo");

        $response->assertOk();
        $response->assertJsonStructure([
            'suggestions' => [
                '*' => ['tag', 'usage_count', 'type']
            ],
            'query'
        ]);

        $suggestions = $response->json('suggestions');
        $this->assertNotEmpty($suggestions);
        
        // Should find existing tags containing 'photo'
        $tags = collect($suggestions)->pluck('tag');
        $this->assertContains('photography', $tags);
        $this->assertContains('photo-editing', $tags);
    }

    /**
     * Test: Permission checks
     */
    public function test_permission_checks(): void
    {
        // Remove user permissions
        $this->user->tenants()->updateExistingPivot($this->tenant->id, [
            'permissions' => json_encode([]) // No permissions
        ]);

        // Should deny access
        $response = $this->getJson("/api/assets/{$this->asset->id}/tags");
        $response->assertStatus(403);

        $response = $this->postJson("/api/assets/{$this->asset->id}/tags", ['tag' => 'test']);
        $response->assertStatus(403);

        $response = $this->deleteJson("/api/assets/{$this->asset->id}/tags/1");
        $response->assertStatus(403);

        $response = $this->getJson("/api/assets/{$this->asset->id}/tags/autocomplete?q=test");
        $response->assertStatus(403);
    }

    /**
     * Test: Tenant isolation
     */
    public function test_tenant_isolation(): void
    {
        // Create another tenant
        $otherTenant = Tenant::create([
            'name' => 'Other Tenant',
            'slug' => 'other-tenant',
        ]);

        $otherAsset = Asset::factory()->create(['tenant_id' => $otherTenant->id]);

        // Should not access other tenant's asset
        $response = $this->getJson("/api/assets/{$otherAsset->id}/tags");
        $response->assertStatus(404);
    }

    /**
     * Test: Invalid tag validation
     */
    public function test_invalid_tag_validation(): void
    {
        // Too short
        $response = $this->postJson("/api/assets/{$this->asset->id}/tags", ['tag' => 'a']);
        $response->assertStatus(422);

        // Too long
        $response = $this->postJson("/api/assets/{$this->asset->id}/tags", [
            'tag' => str_repeat('a', 100)
        ]);
        $response->assertStatus(422);

        // Empty
        $response = $this->postJson("/api/assets/{$this->asset->id}/tags", ['tag' => '']);
        $response->assertStatus(422);
    }
}