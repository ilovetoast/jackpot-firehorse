<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Collection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * C9: Inline collection creation authorization (uploader / asset drawer).
 *
 * Ensures POST /app/collections uses brand-scoped CollectionPolicy::create(User, Brand)
 * so it succeeds from both the Collections page and the uploader modal.
 */
class CollectionInlineCreateAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected User $adminUser;
    protected User $contributorUser;
    protected User $viewerUser;
    protected User $notInBrandUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        $this->brand = Brand::create(['tenant_id' => $this->tenant->id, 'name' => 'B', 'slug' => 'b']);

        $this->adminUser = User::create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Admin',
            'last_name' => 'User',
        ]);
        $this->adminUser->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $this->adminUser->brands()->attach($this->brand->id, ['role' => 'admin', 'removed_at' => null]);

        $this->contributorUser = User::create([
            'email' => 'contributor@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Contributor',
            'last_name' => 'User',
        ]);
        $this->contributorUser->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $this->contributorUser->brands()->attach($this->brand->id, ['role' => 'contributor', 'removed_at' => null]);

        $this->viewerUser = User::create([
            'email' => 'viewer@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Viewer',
            'last_name' => 'User',
        ]);
        $this->viewerUser->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $this->viewerUser->brands()->attach($this->brand->id, ['role' => 'viewer', 'removed_at' => null]);

        $this->notInBrandUser = User::create([
            'email' => 'other@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Other',
            'last_name' => 'User',
        ]);
        $this->notInBrandUser->tenants()->attach($this->tenant->id, ['role' => 'member']);
        // No brand_user row — not in this brand
    }

    public function test_brand_admin_can_create_collection_from_uploader(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson('/app/collections', [
                'name' => 'Uploader Collection',
                'description' => 'From uploader',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('collection.name', 'Uploader Collection');
        $this->assertDatabaseHas('collections', [
            'brand_id' => $this->brand->id,
            'name' => 'Uploader Collection',
            'created_by' => $this->adminUser->id,
        ]);
    }

    public function test_brand_contributor_can_create_collection_from_uploader(): void
    {
        $response = $this->actingAs($this->contributorUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson('/app/collections', [
                'name' => 'Contributor Inline',
                'description' => null,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('collections', [
            'brand_id' => $this->brand->id,
            'name' => 'Contributor Inline',
            'created_by' => $this->contributorUser->id,
        ]);
    }

    public function test_brand_viewer_cannot_create_collection(): void
    {
        $response = $this->actingAs($this->viewerUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson('/app/collections', [
                'name' => 'Viewer Tries',
                'description' => null,
            ]);

        // Policy denies: 403; or middleware/redirect may occur
        $this->assertTrue(in_array($response->status(), [302, 403], true));
        $this->assertDatabaseMissing('collections', [
            'brand_id' => $this->brand->id,
            'name' => 'Viewer Tries',
        ]);
    }

    public function test_user_without_brand_membership_cannot_create(): void
    {
        $response = $this->actingAs($this->notInBrandUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson('/app/collections', [
                'name' => 'No Brand User',
                'description' => null,
            ]);

        // Middleware redirects (302) or policy returns 403; collection must not be created
        $this->assertTrue(in_array($response->status(), [302, 403], true));
        $this->assertDatabaseMissing('collections', [
            'brand_id' => $this->brand->id,
            'name' => 'No Brand User',
        ]);
    }

    public function test_uploader_create_uses_same_policy_as_collections_page(): void
    {
        $payload = [
            'name' => 'Same Policy',
            'description' => 'Same result',
        ];

        // Simulate collections page: session has tenant + brand
        $fromCollectionsPage = $this->actingAs($this->adminUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson('/app/collections', $payload);

        $fromCollectionsPage->assertStatus(201);
        $fromCollectionsPage->assertJsonPath('collection.name', 'Same Policy');

        // Simulate uploader: same payload, same session (brand context from session)
        $fromUploader = $this->actingAs($this->adminUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson('/app/collections', [
                'name' => 'Same Policy From Uploader',
                'description' => 'Same result',
            ]);

        $fromUploader->assertStatus(201);
        $fromUploader->assertJsonPath('collection.name', 'Same Policy From Uploader');

        $this->assertDatabaseCount('collections', 2);
    }
}
