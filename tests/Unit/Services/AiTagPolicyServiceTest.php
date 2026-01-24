<?php

namespace Tests\Unit\Services;

use App\Models\Asset;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Services\AiTagPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AI Tag Policy Service Test
 *
 * Phase J.2.2: Comprehensive testing of tenant-level AI tagging controls
 * to ensure proper enforcement of policy settings with safe defaults.
 */
class AiTagPolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    protected AiTagPolicyService $service;
    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AiTagPolicyService();
        
        // Create test tenant
        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);
    }

    /**
     * Test: Default settings preserve existing behavior (AI enabled, suggestions on, auto-apply off)
     */
    public function test_default_settings_preserve_existing_behavior(): void
    {
        // No settings record exists - should use safe defaults
        $this->assertTrue($this->service->isAiTaggingEnabled($this->tenant));
        $this->assertTrue($this->service->areAiTagSuggestionsEnabled($this->tenant));
        $this->assertFalse($this->service->isAiTagAutoApplyEnabled($this->tenant)); // OFF by default
        $this->assertEquals(5, $this->service->getAutoApplyTagLimit($this->tenant)); // Best practices default
    }

    /**
     * Test: Master toggle disables all AI tagging (hard stop)
     */
    public function test_master_toggle_disables_ai_tagging(): void
    {
        // Create settings with AI disabled
        $this->service->updateTenantSettings($this->tenant, [
            'disable_ai_tagging' => true,
        ]);

        $this->assertFalse($this->service->isAiTaggingEnabled($this->tenant));
        
        // Other settings don't matter when master toggle is off
        $this->assertTrue($this->service->areAiTagSuggestionsEnabled($this->tenant)); // Still true
        $this->assertFalse($this->service->isAiTagAutoApplyEnabled($this->tenant)); // Still false
    }

    /**
     * Test: Suggestion toggle controls suggestion visibility
     */
    public function test_suggestion_toggle_controls_suggestions(): void
    {
        $this->service->updateTenantSettings($this->tenant, [
            'enable_ai_tag_suggestions' => false,
        ]);

        $this->assertTrue($this->service->isAiTaggingEnabled($this->tenant)); // Still enabled
        $this->assertFalse($this->service->areAiTagSuggestionsEnabled($this->tenant)); // Disabled
    }

    /**
     * Test: Auto-apply toggle (OFF by default per requirement)
     */
    public function test_auto_apply_toggle_off_by_default(): void
    {
        // Default state
        $this->assertFalse($this->service->isAiTagAutoApplyEnabled($this->tenant));

        // Enable auto-apply
        $this->service->updateTenantSettings($this->tenant, [
            'enable_ai_tag_auto_apply' => true,
        ]);

        $this->assertTrue($this->service->isAiTagAutoApplyEnabled($this->tenant));
    }

    /**
     * Test: Auto-apply tag limit modes
     */
    public function test_auto_apply_tag_limits(): void
    {
        // Default: best_practices mode
        $this->assertEquals(5, $this->service->getAutoApplyTagLimit($this->tenant));

        // Custom mode with specific limit
        $this->service->updateTenantSettings($this->tenant, [
            'ai_auto_tag_limit_mode' => 'custom',
            'ai_auto_tag_limit_value' => 3,
        ]);

        $this->assertEquals(3, $this->service->getAutoApplyTagLimit($this->tenant));

        // Custom mode with null value (fallback to best practices)
        $this->service->updateTenantSettings($this->tenant, [
            'ai_auto_tag_limit_mode' => 'custom',
            'ai_auto_tag_limit_value' => null,
        ]);

        $this->assertEquals(5, $this->service->getAutoApplyTagLimit($this->tenant));
    }

    /**
     * Test: Asset-level policy evaluation
     */
    public function test_asset_level_policy_evaluation(): void
    {
        $asset = $this->createAsset();

        // Default: should proceed
        $result = $this->service->shouldProceedWithAiTagging($asset);
        $this->assertTrue($result['should_proceed']);

        // Disable AI tagging
        $this->service->updateTenantSettings($this->tenant, [
            'disable_ai_tagging' => true,
        ]);

        $result = $this->service->shouldProceedWithAiTagging($asset);
        $this->assertFalse($result['should_proceed']);
        $this->assertEquals('ai_tagging_disabled', $result['reason']);
    }

    /**
     * Test: Tag selection for auto-apply
     */
    public function test_tag_selection_for_auto_apply(): void
    {
        $asset = $this->createAsset();

        // Auto-apply disabled by default
        $candidates = [
            ['tag' => 'tag1', 'confidence' => 0.95],
            ['tag' => 'tag2', 'confidence' => 0.90],
            ['tag' => 'tag3', 'confidence' => 0.85],
        ];

        $selected = $this->service->selectTagsForAutoApply($asset, $candidates);
        $this->assertEmpty($selected); // No auto-apply by default

        // Enable auto-apply with limit of 2
        $this->service->updateTenantSettings($this->tenant, [
            'enable_ai_tag_auto_apply' => true,
            'ai_auto_tag_limit_mode' => 'custom',
            'ai_auto_tag_limit_value' => 2,
        ]);

        $selected = $this->service->selectTagsForAutoApply($asset, $candidates);
        $this->assertCount(2, $selected);
        
        // Should select highest confidence tags
        $this->assertEquals('tag1', $selected[0]['tag']);
        $this->assertEquals('tag2', $selected[1]['tag']);
    }

    /**
     * Test: Comprehensive policy status
     */
    public function test_comprehensive_policy_status(): void
    {
        $asset = $this->createAsset();

        $status = $this->service->getPolicyStatus($asset);

        $this->assertTrue($status['tenant_found']);
        $this->assertEquals($this->tenant->id, $status['tenant_id']);
        $this->assertTrue($status['ai_tagging_enabled']);
        $this->assertTrue($status['ai_suggestions_enabled']);
        $this->assertFalse($status['ai_auto_apply_enabled']);
        $this->assertEquals(5, $status['auto_apply_limit']);
        $this->assertTrue($status['should_proceed']);
    }

    /**
     * Test: Settings validation
     */
    public function test_settings_validation(): void
    {
        // Invalid limit mode
        $this->expectException(\InvalidArgumentException::class);
        $this->service->updateTenantSettings($this->tenant, [
            'ai_auto_tag_limit_mode' => 'invalid',
        ]);
    }

    /**
     * Test: Settings validation - limit value too low
     */
    public function test_settings_validation_limit_too_low(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->updateTenantSettings($this->tenant, [
            'ai_auto_tag_limit_value' => 0,
        ]);
    }

    /**
     * Test: Tenant isolation 
     */
    public function test_tenant_isolation(): void
    {
        // Create another tenant
        $tenant2 = Tenant::create([
            'name' => 'Tenant 2',
            'slug' => 'tenant-2',
        ]);

        // Configure tenant 1 to disable AI
        $this->service->updateTenantSettings($this->tenant, [
            'disable_ai_tagging' => true,
        ]);

        // Configure tenant 2 to enable auto-apply
        $this->service->updateTenantSettings($tenant2, [
            'enable_ai_tag_auto_apply' => true,
        ]);

        // Verify isolation
        $this->assertFalse($this->service->isAiTaggingEnabled($this->tenant));
        $this->assertTrue($this->service->isAiTaggingEnabled($tenant2));

        $this->assertFalse($this->service->isAiTagAutoApplyEnabled($this->tenant));
        $this->assertTrue($this->service->isAiTagAutoApplyEnabled($tenant2));
    }

    /**
     * Test: Cache functionality
     */
    public function test_cache_functionality(): void
    {
        // Set initial settings
        $this->service->updateTenantSettings($this->tenant, [
            'disable_ai_tagging' => true,
        ]);

        // First call should cache
        $this->assertFalse($this->service->isAiTaggingEnabled($this->tenant));

        // Verify cache exists
        $cacheKey = "tenant_ai_tag_settings:{$this->tenant->id}";
        $cached = Cache::get($cacheKey);
        $this->assertNotNull($cached);

        // Clear cache and update settings
        $this->service->clearCache($this->tenant);
        $this->assertNull(Cache::get($cacheKey));

        // Update settings (should clear cache)
        $this->service->updateTenantSettings($this->tenant, [
            'disable_ai_tagging' => false,
        ]);

        $this->assertTrue($this->service->isAiTaggingEnabled($this->tenant));
    }

    /**
     * Test: Bulk tenant status check
     */
    public function test_bulk_tenant_status(): void
    {
        // Create additional tenants
        $tenant2 = Tenant::create(['name' => 'Tenant 2', 'slug' => 'tenant-2']);
        $tenant3 = Tenant::create(['name' => 'Tenant 3', 'slug' => 'tenant-3']);

        // Configure tenant 2
        $this->service->updateTenantSettings($tenant2, [
            'disable_ai_tagging' => true,
        ]);

        // Bulk check
        $statuses = $this->service->bulkGetTenantStatus([
            $this->tenant->id,
            $tenant2->id,
            $tenant3->id,
        ]);

        $this->assertCount(3, $statuses);
        $this->assertFalse($statuses[$this->tenant->id]['disable_ai_tagging']); // Default
        $this->assertTrue($statuses[$tenant2->id]['disable_ai_tagging']); // Set
        $this->assertFalse($statuses[$tenant3->id]['disable_ai_tagging']); // Default
    }

    /**
     * Test: Default settings helper
     */
    public function test_default_settings_helper(): void
    {
        $defaults = $this->service->getDefaultSettings();

        $this->assertFalse($defaults['disable_ai_tagging']);
        $this->assertTrue($defaults['enable_ai_tag_suggestions']);
        $this->assertFalse($defaults['enable_ai_tag_auto_apply']); // OFF by default
        $this->assertEquals('best_practices', $defaults['ai_auto_tag_limit_mode']);
        $this->assertNull($defaults['ai_auto_tag_limit_value']);
    }

    /**
     * Test: Missing tenant handling
     */
    public function test_missing_tenant_handling(): void
    {
        // Create asset with non-existent tenant
        $invalidAsset = new Asset([
            'tenant_id' => 99999,
            'id' => 'test-asset-id',
        ]);

        $result = $this->service->shouldProceedWithAiTagging($invalidAsset);
        $this->assertFalse($result['should_proceed']);
        $this->assertEquals('tenant_not_found', $result['reason']);

        $status = $this->service->getPolicyStatus($invalidAsset);
        $this->assertFalse($status['tenant_found']);
        $this->assertEquals('tenant_not_found', $status['reason']);
    }

    /**
     * Test: Settings update with partial data
     */
    public function test_partial_settings_update(): void
    {
        // Set initial settings
        $this->service->updateTenantSettings($this->tenant, [
            'disable_ai_tagging' => true,
            'enable_ai_tag_auto_apply' => true,
        ]);

        // Update only one setting
        $this->service->updateTenantSettings($this->tenant, [
            'disable_ai_tagging' => false,
        ]);

        // Verify first setting updated, second preserved
        $this->assertTrue($this->service->isAiTaggingEnabled($this->tenant));
        $this->assertTrue($this->service->isAiTagAutoApplyEnabled($this->tenant));
    }

    /**
     * Helper: Create test asset
     */
    protected function createAsset(): Asset
    {
        $brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);

        $storageBucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'test-bucket',
            'status' => \App\Enums\StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        $uploadSession = UploadSession::create([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'brand_id' => $brand->id,
            'storage_bucket_id' => $storageBucket->id,
            'status' => \App\Enums\UploadStatus::COMPLETED,
            'type' => \App\Enums\UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        return Asset::create([
            'tenant_id' => $this->tenant->id,
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