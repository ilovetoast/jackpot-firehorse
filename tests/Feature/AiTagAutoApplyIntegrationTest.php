<?php

namespace Tests\Feature;

use App\Jobs\AiTagAutoApplyJob;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Services\AiTagAutoApplyService;
use App\Services\AiTagPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * AI Tag Auto-Apply Integration Test
 *
 * Phase J.2.2: End-to-end testing of AI tag auto-application
 * with policy controls, normalization, and limits.
 */
class AiTagAutoApplyIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected Asset $asset;
    protected AiTagAutoApplyService $autoApplyService;
    protected AiTagPolicyService $policyService;

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

        // Initialize services
        $this->autoApplyService = app(AiTagAutoApplyService::class);
        $this->policyService = app(AiTagPolicyService::class);
    }

    /**
     * Test: Auto-apply disabled by default (no tags applied)
     */
    public function test_auto_apply_disabled_by_default(): void
    {
        // Create tag candidates
        $this->createTagCandidates([
            ['tag' => 'high-quality', 'confidence' => 0.95],
            ['tag' => 'portrait', 'confidence' => 0.90],
        ]);

        // Process auto-apply (should be disabled by default)
        $result = $this->autoApplyService->processAutoApply($this->asset);

        $this->assertEquals(0, $result['auto_applied']);
        $this->assertEquals('auto_apply_disabled', $result['reason']);

        // Verify no tags were auto-applied
        $this->assertDatabaseMissing('asset_tags', [
            'asset_id' => $this->asset->id,
            'source' => 'ai:auto',
        ]);
    }

    /**
     * Test: Auto-apply with policy enabled
     */
    public function test_auto_apply_with_policy_enabled(): void
    {
        // Enable auto-apply
        $this->policyService->updateTenantSettings($this->tenant, [
            'enable_ai_tag_auto_apply' => true,
            'ai_auto_tag_limit_mode' => 'custom',
            'ai_auto_tag_limit_value' => 3,
        ]);

        // Create tag candidates with varying confidence
        $this->createTagCandidates([
            ['tag' => 'high-quality', 'confidence' => 0.95],
            ['tag' => 'portrait', 'confidence' => 0.90],
            ['tag' => 'professional', 'confidence' => 0.85],
            ['tag' => 'outdoor', 'confidence' => 0.80], // Should not be selected (limit = 3)
        ]);

        // Process auto-apply
        $result = $this->autoApplyService->processAutoApply($this->asset);

        $this->assertEquals(3, $result['auto_applied']);
        $this->assertEquals(1, $result['skipped']);

        // Verify highest confidence tags were auto-applied
        $this->assertDatabaseHas('asset_tags', [
            'asset_id' => $this->asset->id,
            'tag' => 'high-quality',
            'source' => 'ai:auto',
        ]);

        $this->assertDatabaseHas('asset_tags', [
            'asset_id' => $this->asset->id,
            'tag' => 'portrait',
            'source' => 'ai:auto',
        ]);

        $this->assertDatabaseHas('asset_tags', [
            'asset_id' => $this->asset->id,
            'tag' => 'professional',
            'source' => 'ai:auto',
        ]);

        // Verify lower confidence tag was not auto-applied
        $this->assertDatabaseMissing('asset_tags', [
            'asset_id' => $this->asset->id,
            'tag' => 'outdoor',
            'source' => 'ai:auto',
        ]);
    }

    /**
     * Test: Auto-apply respects tag normalization
     */
    public function test_auto_apply_respects_normalization(): void
    {
        // Enable auto-apply
        $this->policyService->updateTenantSettings($this->tenant, [
            'enable_ai_tag_auto_apply' => true,
        ]);

        // Create tag candidates with non-canonical forms
        $this->createTagCandidates([
            ['tag' => 'Hi-Res Photos!', 'confidence' => 0.95], // Should normalize
            ['tag' => 'PROFESSIONAL', 'confidence' => 0.90], // Should normalize
        ]);

        // Process auto-apply
        $result = $this->autoApplyService->processAutoApply($this->asset);

        $this->assertEquals(2, $result['auto_applied']);

        // Verify tags were stored in normalized form
        $this->assertDatabaseHas('asset_tags', [
            'asset_id' => $this->asset->id,
            'tag' => 'hi-res-photo', // Normalized form
            'source' => 'ai:auto',
        ]);

        $this->assertDatabaseHas('asset_tags', [
            'asset_id' => $this->asset->id,
            'tag' => 'professional', // Normalized form
            'source' => 'ai:auto',
        ]);
    }

    /**
     * Test: Auto-apply respects block list
     */
    public function test_auto_apply_respects_block_list(): void
    {
        // Create blocked tag rule
        DB::table('tag_rules')->insert([
            'tenant_id' => $this->tenant->id,
            'tag' => 'spam',
            'rule_type' => 'block',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Enable auto-apply
        $this->policyService->updateTenantSettings($this->tenant, [
            'enable_ai_tag_auto_apply' => true,
        ]);

        // Create candidates including blocked tag
        $this->createTagCandidates([
            ['tag' => 'SPAM!', 'confidence' => 0.95], // Normalizes to 'spam' (blocked)
            ['tag' => 'allowed', 'confidence' => 0.90],
        ]);

        // Process auto-apply
        $result = $this->autoApplyService->processAutoApply($this->asset);

        $this->assertEquals(1, $result['auto_applied']); // Only allowed tag
        $this->assertEquals(1, $result['skipped']); // Blocked tag

        // Verify only allowed tag was applied
        $this->assertDatabaseHas('asset_tags', [
            'asset_id' => $this->asset->id,
            'tag' => 'allowed',
            'source' => 'ai:auto',
        ]);

        $this->assertDatabaseMissing('asset_tags', [
            'asset_id' => $this->asset->id,
            'tag' => 'spam',
        ]);
    }

    /**
     * Test: Auto-apply prevents duplicates
     */
    public function test_auto_apply_prevents_duplicates(): void
    {
        // Create existing tag
        DB::table('asset_tags')->insert([
            'asset_id' => $this->asset->id,
            'tag' => 'existing-tag',
            'source' => 'user',
            'created_at' => now(),
        ]);

        // Enable auto-apply
        $this->policyService->updateTenantSettings($this->tenant, [
            'enable_ai_tag_auto_apply' => true,
        ]);

        // Create candidates including duplicate
        $this->createTagCandidates([
            ['tag' => 'existing-tag', 'confidence' => 0.95], // Duplicate
            ['tag' => 'new-tag', 'confidence' => 0.90],
        ]);

        // Process auto-apply
        $result = $this->autoApplyService->processAutoApply($this->asset);

        $this->assertEquals(1, $result['auto_applied']); // Only new tag

        // Verify only one instance of existing tag
        $existingCount = DB::table('asset_tags')
            ->where('asset_id', $this->asset->id)
            ->where('tag', 'existing-tag')
            ->count();
        
        $this->assertEquals(1, $existingCount);

        // Verify new tag was added
        $this->assertDatabaseHas('asset_tags', [
            'asset_id' => $this->asset->id,
            'tag' => 'new-tag',
            'source' => 'ai:auto',
        ]);
    }

    /**
     * Test: Auto-applied tags are removable
     */
    public function test_auto_applied_tags_are_removable(): void
    {
        // Enable auto-apply
        $this->policyService->updateTenantSettings($this->tenant, [
            'enable_ai_tag_auto_apply' => true,
        ]);

        // Create and process candidates
        $this->createTagCandidates([
            ['tag' => 'removable', 'confidence' => 0.95],
        ]);

        $this->autoApplyService->processAutoApply($this->asset);

        // Verify tag was auto-applied
        $this->assertDatabaseHas('asset_tags', [
            'asset_id' => $this->asset->id,
            'tag' => 'removable',
            'source' => 'ai:auto',
        ]);

        // Remove the auto-applied tag
        $removed = $this->autoApplyService->removeAutoAppliedTag($this->asset, 'removable');
        $this->assertTrue($removed);

        // Verify tag was removed
        $this->assertDatabaseMissing('asset_tags', [
            'asset_id' => $this->asset->id,
            'tag' => 'removable',
            'source' => 'ai:auto',
        ]);
    }

    /**
     * Test: Cannot remove non-auto-applied tags
     */
    public function test_cannot_remove_non_auto_applied_tags(): void
    {
        // Create user-applied tag
        DB::table('asset_tags')->insert([
            'asset_id' => $this->asset->id,
            'tag' => 'user-tag',
            'source' => 'user',
            'created_at' => now(),
        ]);

        // Attempt to remove via auto-apply service
        $removed = $this->autoApplyService->removeAutoAppliedTag($this->asset, 'user-tag');
        $this->assertFalse($removed);

        // Verify tag still exists
        $this->assertDatabaseHas('asset_tags', [
            'asset_id' => $this->asset->id,
            'tag' => 'user-tag',
            'source' => 'user',
        ]);
    }

    /**
     * Test: Job integration
     */
    public function test_job_integration(): void
    {
        Queue::fake();

        // Enable auto-apply
        $this->policyService->updateTenantSettings($this->tenant, [
            'enable_ai_tag_auto_apply' => true,
        ]);

        // Create tag candidates
        $this->createTagCandidates([
            ['tag' => 'job-test', 'confidence' => 0.95],
        ]);

        // Execute job
        $job = new AiTagAutoApplyJob($this->asset->id);
        $job->handle($this->autoApplyService);

        // Verify tag was auto-applied
        $this->assertDatabaseHas('asset_tags', [
            'asset_id' => $this->asset->id,
            'tag' => 'job-test',
            'source' => 'ai:auto',
        ]);
    }

    /**
     * Test: Job handles missing asset gracefully
     */
    public function test_job_handles_missing_asset(): void
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        
        $job = new AiTagAutoApplyJob('non-existent-asset-id');
        $job->handle($this->autoApplyService);
    }

    /**
     * Test: Get auto-applied tags
     */
    public function test_get_auto_applied_tags(): void
    {
        // Create mix of tags
        DB::table('asset_tags')->insert([
            [
                'asset_id' => $this->asset->id,
                'tag' => 'auto-tag-1',
                'source' => 'ai:auto',
                'created_at' => now()->subMinute(),
            ],
            [
                'asset_id' => $this->asset->id,
                'tag' => 'user-tag',
                'source' => 'user',
                'created_at' => now(),
            ],
            [
                'asset_id' => $this->asset->id,
                'tag' => 'auto-tag-2',
                'source' => 'ai:auto',
                'created_at' => now(),
            ],
        ]);

        // Get auto-applied tags only
        $autoTags = $this->autoApplyService->getAutoAppliedTags($this->asset);
        
        $this->assertCount(2, $autoTags);
        $tagNames = array_column($autoTags, 'tag');
        $this->assertContains('auto-tag-1', $tagNames);
        $this->assertContains('auto-tag-2', $tagNames);
        $this->assertNotContains('user-tag', $tagNames);
    }

    /**
     * Test: Master toggle prevents auto-apply
     */
    public function test_master_toggle_prevents_auto_apply(): void
    {
        // Enable auto-apply but disable all AI tagging
        $this->policyService->updateTenantSettings($this->tenant, [
            'disable_ai_tagging' => true,
            'enable_ai_tag_auto_apply' => true,
        ]);

        // Create candidates
        $this->createTagCandidates([
            ['tag' => 'blocked-by-master', 'confidence' => 0.95],
        ]);

        // Should not process due to master toggle
        $shouldProcess = $this->autoApplyService->shouldProcessAutoApply($this->asset);
        $this->assertFalse($shouldProcess);

        $result = $this->autoApplyService->processAutoApply($this->asset);
        $this->assertEquals(0, $result['auto_applied']);
        $this->assertEquals('tenant_not_found', $result['reason']); // Service checks master toggle internally
    }

    /**
     * Helper: Create tag candidates
     */
    protected function createTagCandidates(array $candidates): void
    {
        foreach ($candidates as $candidate) {
            DB::table('asset_tag_candidates')->insert([
                'asset_id' => $this->asset->id,
                'tag' => $candidate['tag'],
                'source' => 'ai',
                'confidence' => $candidate['confidence'],
                'producer' => 'ai',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}