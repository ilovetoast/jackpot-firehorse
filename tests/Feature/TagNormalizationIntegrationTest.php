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
 * Tag Normalization Integration Test
 *
 * Phase J.2.1: End-to-end testing of tag normalization in acceptance 
 * and dismissal workflows to ensure canonical form enforcement.
 */
class TagNormalizationIntegrationTest extends TestCase
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
                'metadata.suggestions.apply',
                'metadata.suggestions.dismiss'
            ])
        ]);

        // Create storage bucket
        $storageBucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'test-bucket',
            'status' => \App\Enums\StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        // Create upload session
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
     * Test: Tag acceptance normalizes to canonical form
     */
    public function test_tag_acceptance_normalizes_to_canonical_form(): void
    {
        // Create tag candidate with non-canonical form
        $candidateId = DB::table('asset_tag_candidates')->insertGetId([
            'asset_id' => $this->asset->id,
            'tag' => 'Hi-Res Photos!', // Will normalize to 'hi-res-photo'
            'source' => 'ai',
            'confidence' => 0.95,
            'producer' => 'ai',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Accept the tag suggestion
        $response = $this->postJson("/app/assets/{$this->asset->id}/tags/suggestions/{$candidateId}/accept");

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Tag accepted',
            'canonical_tag' => 'hi-res-photo', // Normalized form
        ]);

        // Verify canonical tag is stored in asset_tags
        $this->assertDatabaseHas('asset_tags', [
            'asset_id' => $this->asset->id,
            'tag' => 'hi-res-photo', // Canonical form stored
            'source' => 'ai',
        ]);

        // Verify candidate is marked as resolved
        $this->assertDatabaseHas('asset_tag_candidates', [
            'id' => $candidateId,
        ]);
        $this->assertDatabaseMissing('asset_tag_candidates', [
            'id' => $candidateId,
            'resolved_at' => null,
        ]);
    }

    /**
     * Test: Blocked tag rejection during acceptance
     */
    public function test_blocked_tag_rejection_during_acceptance(): void
    {
        // Create blocked tag rule
        DB::table('tag_rules')->insert([
            'tenant_id' => $this->tenant->id,
            'tag' => 'spam',
            'rule_type' => 'block',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create tag candidate that normalizes to blocked tag
        $candidateId = DB::table('asset_tag_candidates')->insertGetId([
            'asset_id' => $this->asset->id,
            'tag' => 'SPAM!', // Normalizes to 'spam' which is blocked
            'source' => 'ai',
            'confidence' => 0.95,
            'producer' => 'ai',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Attempt to accept blocked tag
        $response = $this->postJson("/app/assets/{$this->asset->id}/tags/suggestions/{$candidateId}/accept");

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'Tag cannot be accepted (blocked or invalid after normalization)',
            'original_tag' => 'SPAM!',
        ]);

        // Verify no tag was created
        $this->assertDatabaseMissing('asset_tags', [
            'asset_id' => $this->asset->id,
            'tag' => 'spam',
        ]);

        // Verify candidate is not marked as resolved
        $this->assertDatabaseHas('asset_tag_candidates', [
            'id' => $candidateId,
            'resolved_at' => null,
        ]);
    }

    /**
     * Test: Duplicate prevention with canonical forms
     */
    public function test_duplicate_prevention_with_canonical_forms(): void
    {
        // Create existing tag in canonical form
        DB::table('asset_tags')->insert([
            'asset_id' => $this->asset->id,
            'tag' => 'hi-res',
            'source' => 'user',
            'created_at' => now(),
        ]);

        // Create candidate that normalizes to same canonical form
        $candidateId = DB::table('asset_tag_candidates')->insertGetId([
            'asset_id' => $this->asset->id,
            'tag' => 'Hi Res!', // Normalizes to 'hi-res'
            'source' => 'ai',
            'confidence' => 0.95,
            'producer' => 'ai',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Accept the tag suggestion
        $response = $this->postJson("/app/assets/{$this->asset->id}/tags/suggestions/{$candidateId}/accept");

        $response->assertStatus(200);
        $response->assertJson([
            'canonical_tag' => 'hi-res',
        ]);

        // Verify no duplicate tag was created
        $tags = DB::table('asset_tags')
            ->where('asset_id', $this->asset->id)
            ->where('tag', 'hi-res')
            ->get();
        
        $this->assertCount(1, $tags, 'Should not create duplicate canonical tags');
    }

    /**
     * Test: Tag dismissal affects canonical form
     */
    public function test_tag_dismissal_affects_canonical_form(): void
    {
        // Create multiple candidates that normalize to same canonical form
        $candidate1Id = DB::table('asset_tag_candidates')->insertGetId([
            'asset_id' => $this->asset->id,
            'tag' => 'Hi-Res',
            'source' => 'ai',
            'confidence' => 0.95,
            'producer' => 'ai',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $candidate2Id = DB::table('asset_tag_candidates')->insertGetId([
            'asset_id' => $this->asset->id,
            'tag' => 'hi res',
            'source' => 'ai',
            'confidence' => 0.93,
            'producer' => 'ai',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $candidate3Id = DB::table('asset_tag_candidates')->insertGetId([
            'asset_id' => $this->asset->id,
            'tag' => 'HIGH-RES!',
            'source' => 'ai',
            'confidence' => 0.91,
            'producer' => 'ai',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Dismiss one candidate
        $response = $this->postJson("/app/assets/{$this->asset->id}/tags/suggestions/{$candidate1Id}/dismiss");

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Tag dismissed',
            'canonical_tag' => 'hi-res',
        ]);

        // Verify ALL candidates that normalize to same canonical form are dismissed
        $this->assertDatabaseMissing('asset_tag_candidates', [
            'id' => $candidate1Id,
            'dismissed_at' => null,
        ]);
        $this->assertDatabaseMissing('asset_tag_candidates', [
            'id' => $candidate2Id,
            'dismissed_at' => null,
        ]);
        $this->assertDatabaseMissing('asset_tag_candidates', [
            'id' => $candidate3Id,
            'dismissed_at' => null,
        ]);
    }

    /**
     * Test: Synonym resolution during acceptance
     */
    public function test_synonym_resolution_during_acceptance(): void
    {
        // Create synonym mapping
        DB::table('tag_synonyms')->insert([
            'tenant_id' => $this->tenant->id,
            'synonym_tag' => 'high-resolution',
            'canonical_tag' => 'hi-res',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create candidate that uses synonym
        $candidateId = DB::table('asset_tag_candidates')->insertGetId([
            'asset_id' => $this->asset->id,
            'tag' => 'HIGH RESOLUTION', // Normalizes to 'high-resolution', resolves to 'hi-res'
            'source' => 'ai',
            'confidence' => 0.95,
            'producer' => 'ai',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Accept the tag suggestion
        $response = $this->postJson("/app/assets/{$this->asset->id}/tags/suggestions/{$candidateId}/accept");

        $response->assertStatus(200);
        $response->assertJson([
            'canonical_tag' => 'hi-res', // Resolved via synonym
        ]);

        // Verify canonical tag is stored
        $this->assertDatabaseHas('asset_tags', [
            'asset_id' => $this->asset->id,
            'tag' => 'hi-res', // Synonym resolved
            'source' => 'ai',
        ]);
    }

    /**
     * Test: AI generation respects dismissed canonical forms
     */
    public function test_ai_generation_respects_dismissed_canonical_forms(): void
    {
        // Manually dismiss a tag (simulate previous dismissal)
        $existingCandidateId = DB::table('asset_tag_candidates')->insertGetId([
            'asset_id' => $this->asset->id,
            'tag' => 'hi-res',
            'source' => 'ai',
            'producer' => 'ai',
            'dismissed_at' => now()->subHour(),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        // Now simulate AI generation trying to create candidates that normalize to dismissed form
        $aiGenerationService = app(\App\Services\AiMetadataGenerationService::class);
        
        $tagsToCreate = [
            ['value' => 'Hi-Res', 'confidence' => 0.95], // Normalizes to 'hi-res'
            ['value' => 'hi res', 'confidence' => 0.93], // Normalizes to 'hi-res' 
            ['value' => 'allowed-tag', 'confidence' => 0.91], // Different canonical form
        ];

        // Use reflection to call private method (or make it public for testing)
        $reflection = new \ReflectionClass($aiGenerationService);
        $createTagsMethod = $reflection->getMethod('createTags');
        $createTagsMethod->setAccessible(true);

        $created = $createTagsMethod->invoke($aiGenerationService, $this->asset, $tagsToCreate);

        // Should only create 1 candidate (the allowed-tag), not the hi-res variants
        $this->assertEquals(1, $created);

        // Verify only allowed-tag candidate was created
        $this->assertDatabaseHas('asset_tag_candidates', [
            'asset_id' => $this->asset->id,
            'tag' => 'allowed-tag',
            'dismissed_at' => null,
        ]);

        // Verify hi-res variants were NOT created
        $hiResCount = DB::table('asset_tag_candidates')
            ->where('asset_id', $this->asset->id)
            ->whereIn('tag', ['Hi-Res', 'hi res'])
            ->whereNull('dismissed_at')
            ->count();
        
        $this->assertEquals(0, $hiResCount, 'Should not create candidates for dismissed canonical forms');
    }

    /**
     * Test: Cross-variant dismissal consistency
     */
    public function test_cross_variant_dismissal_consistency(): void
    {
        // Test the specific requirement: "Hi-Res", "hi res", and "high-resolution" resolve to one tag
        
        // Create synonym for high-resolution
        DB::table('tag_synonyms')->insert([
            'tenant_id' => $this->tenant->id,
            'synonym_tag' => 'high-resolution',
            'canonical_tag' => 'hi-res',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create candidates for all three variants
        $candidates = [];
        $variants = ['Hi-Res', 'hi res', 'HIGH RESOLUTION'];
        
        foreach ($variants as $variant) {
            $candidates[] = DB::table('asset_tag_candidates')->insertGetId([
                'asset_id' => $this->asset->id,
                'tag' => $variant,
                'source' => 'ai',
                'producer' => 'ai',
                'confidence' => 0.95,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Dismiss the first variant
        $response = $this->postJson("/app/assets/{$this->asset->id}/tags/suggestions/{$candidates[0]}/dismiss");
        $response->assertStatus(200);

        // Verify ALL variants are dismissed (they all normalize to 'hi-res')
        foreach ($candidates as $candidateId) {
            $this->assertDatabaseMissing('asset_tag_candidates', [
                'id' => $candidateId,
                'dismissed_at' => null,
            ]);
        }

        // Now try to accept any remaining candidate - should fail because all are dismissed
        $newCandidateId = DB::table('asset_tag_candidates')->insertGetId([
            'asset_id' => $this->asset->id,
            'tag' => 'HI RES PHOTO',
            'source' => 'ai',
            'producer' => 'ai',
            'confidence' => 0.95,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // This should work because it's a different canonical form
        $response = $this->postJson("/app/assets/{$this->asset->id}/tags/suggestions/{$newCandidateId}/accept");
        $response->assertStatus(200);
    }
}