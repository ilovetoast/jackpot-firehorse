<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\TagQualityMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tag Quality Metrics API Tests
 *
 * Phase J.2.6: Tests for tag quality analytics
 */
class TagQualityMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $admin;
    protected User $user;
    protected Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        // Create tenant
        $this->tenant = Tenant::create([
            'name' => 'Test Company',
            'slug' => 'test-company',
        ]);

        // Create admin user (owner)
        $this->admin = User::factory()->create([
            'first_name' => 'Admin',
            'last_name' => 'User',
        ]);

        $this->admin->tenants()->attach($this->tenant->id, [
            'permissions' => json_encode(['companies.settings.edit', 'ai.usage.view'])
        ]);

        // Create regular user
        $this->user = User::factory()->create([
            'first_name' => 'Regular',
            'last_name' => 'User',
        ]);

        $this->user->tenants()->attach($this->tenant->id, [
            'permissions' => json_encode(['assets.view'])
        ]);

        // Create asset
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

        $this->asset = Asset::create([
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

        // Set up app context
        app()->instance('tenant', $this->tenant);
        app()->instance('brand', $brand);
    }

    /**
     * Test: Get tag quality metrics as admin
     */
    public function test_get_tag_quality_metrics_as_admin(): void
    {
        // Make admin the owner
        $this->tenant->owner_user_id = $this->admin->id;
        $this->tenant->save();
        
        Auth::login($this->admin);

        // Create some test data
        $this->createTestTagData();

        $response = $this->getJson('/app/api/companies/ai-tag-metrics');

        $response->assertOk();
        $response->assertJsonStructure([
            'summary' => [
                'time_range',
                'ai_enabled',
                'total_candidates',
                'accepted_candidates',
                'dismissed_candidates',
                'acceptance_rate',
                'dismissal_rate',
            ],
            'tags' => [
                'tags' => [
                    '*' => [
                        'tag',
                        'total_generated',
                        'accepted',
                        'dismissed',
                        'acceptance_rate',
                        'dismissal_rate',
                    ]
                ]
            ],
            'confidence',
            'trust_signals',
        ]);
    }

    /**
     * Test: Cannot access without permission
     */
    public function test_get_tag_quality_metrics_without_permission(): void
    {
        Auth::login($this->user);

        $response = $this->getJson('/app/api/companies/ai-tag-metrics');

        $response->assertStatus(403);
        $response->assertJson([
            'error' => 'You do not have permission to view tag quality metrics.'
        ]);
    }

    /**
     * Test: Export CSV functionality  
     */
    public function test_export_tag_quality_metrics(): void
    {
        // Make admin the owner
        $this->tenant->owner_user_id = $this->admin->id;
        $this->tenant->save();
        
        Auth::login($this->admin);

        // Create test data
        $this->createTestTagData();

        $response = $this->get('/app/api/companies/ai-tag-metrics/export');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $response->assertHeader('content-disposition');

        // Check CSV content has headers
        $content = $response->getContent();
        $this->assertStringContains('Tag,Total Generated,Accepted,Dismissed', $content);
    }

    /**
     * Test: Time range filtering
     */
    public function test_tag_quality_metrics_time_range(): void
    {
        // Make admin the owner
        $this->tenant->owner_user_id = $this->admin->id;
        $this->tenant->save();
        
        Auth::login($this->admin);

        // Create test data for specific month
        $lastMonth = now()->subMonth()->format('Y-m');
        
        DB::table('asset_tag_candidates')->insert([
            'asset_id' => $this->asset->id,
            'tag' => 'last-month-tag',
            'producer' => 'ai',
            'confidence' => 0.95,
            'resolved_at' => now()->subMonth(),
            'created_at' => now()->subMonth(),
            'updated_at' => now()->subMonth(),
        ]);

        $response = $this->getJson('/app/api/companies/ai-tag-metrics?' . http_build_query([
            'time_range' => $lastMonth
        ]));

        $response->assertOk();
        $data = $response->json();
        
        $this->assertEquals($lastMonth, $data['summary']['time_range']);
    }

    /**
     * Test: Tenant isolation
     */
    public function test_tag_quality_metrics_tenant_isolation(): void
    {
        // Create another tenant with its own data
        $otherTenant = Tenant::create([
            'name' => 'Other Company',
            'slug' => 'other-company',
        ]);

        $otherAsset = Asset::factory()->create(['tenant_id' => $otherTenant->id]);

        // Add tag candidate to other tenant
        DB::table('asset_tag_candidates')->insert([
            'asset_id' => $otherAsset->id,
            'tag' => 'other-tenant-tag',
            'producer' => 'ai',
            'confidence' => 0.95,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Make admin the owner and login
        $this->tenant->owner_user_id = $this->admin->id;
        $this->tenant->save();
        Auth::login($this->admin);

        $response = $this->getJson('/app/api/companies/ai-tag-metrics');
        $response->assertOk();

        // Should not contain other tenant's data
        $tags = $response->json('tags.tags');
        $tagNames = array_column($tags, 'tag');
        $this->assertNotContains('other-tenant-tag', $tagNames);
    }

    /**
     * Test: No data state
     */
    public function test_tag_quality_metrics_no_data(): void
    {
        // Make admin the owner
        $this->tenant->owner_user_id = $this->admin->id;
        $this->tenant->save();
        
        Auth::login($this->admin);

        $response = $this->getJson('/app/api/companies/ai-tag-metrics');

        $response->assertOk();
        $data = $response->json();
        
        $this->assertEquals(0, $data['summary']['total_candidates']);
        $this->assertEquals(0, $data['summary']['accepted_candidates']);
    }

    /**
     * Create test tag data for metrics testing.
     */
    protected function createTestTagData(): void
    {
        // Create various tag candidates with different outcomes
        $testData = [
            // Accepted tags
            ['tag' => 'accepted-tag-1', 'confidence' => 0.95, 'resolved_at' => now(), 'dismissed_at' => null],
            ['tag' => 'accepted-tag-2', 'confidence' => 0.92, 'resolved_at' => now(), 'dismissed_at' => null],
            
            // Dismissed tags
            ['tag' => 'dismissed-tag-1', 'confidence' => 0.88, 'resolved_at' => null, 'dismissed_at' => now()],
            ['tag' => 'dismissed-tag-2', 'confidence' => 0.75, 'resolved_at' => null, 'dismissed_at' => now()],
            
            // Unresolved tags
            ['tag' => 'pending-tag-1', 'confidence' => 0.85, 'resolved_at' => null, 'dismissed_at' => null],
        ];

        foreach ($testData as $data) {
            DB::table('asset_tag_candidates')->insert([
                'asset_id' => $this->asset->id,
                'tag' => $data['tag'],
                'producer' => 'ai',
                'confidence' => $data['confidence'],
                'resolved_at' => $data['resolved_at'],
                'dismissed_at' => $data['dismissed_at'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Create corresponding applied tags for accepted ones
        DB::table('asset_tags')->insert([
            [
                'asset_id' => $this->asset->id,
                'tag' => 'accepted-tag-1',
                'source' => 'ai',
                'confidence' => 0.95,
                'created_at' => now(),
            ],
            [
                'asset_id' => $this->asset->id,
                'tag' => 'accepted-tag-2',
                'source' => 'ai',
                'confidence' => 0.92,
                'created_at' => now(),
            ],
            [
                'asset_id' => $this->asset->id,
                'tag' => 'manual-tag-1',
                'source' => 'manual',
                'confidence' => null,
                'created_at' => now(),
            ],
        ]);
    }
}