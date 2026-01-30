<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Collection;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\CollectionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CollectionPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected Collection $collection;
    protected User $adminUser;
    protected User $managerUser;
    protected User $contributorUser;
    protected User $viewerUser;
    protected User $notInBrandUser;
    protected CollectionPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);

        $this->collection = Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Test Collection',
            'visibility' => 'brand',
            'is_public' => false,
        ]);

        $this->adminUser = User::create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Admin',
            'last_name' => 'User',
        ]);
        $this->adminUser->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $this->adminUser->brands()->attach($this->brand->id, ['role' => 'admin', 'removed_at' => null]);

        $this->managerUser = User::create([
            'email' => 'manager@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Manager',
            'last_name' => 'User',
        ]);
        $this->managerUser->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $this->managerUser->brands()->attach($this->brand->id, ['role' => 'brand_manager', 'removed_at' => null]);

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
        // Not attached to brand - no brand_user row

        $this->policy = new CollectionPolicy();
    }

    public function test_brand_admin_can_view_create_update_delete_manage_assets(): void
    {
        $user = $this->adminUser;
        $this->assertTrue($this->policy->view($user, $this->collection));
        $this->assertTrue($this->policy->create($user, $this->brand));
        $this->assertTrue($this->policy->update($user, $this->collection));
        $this->assertTrue($this->policy->delete($user, $this->collection));
        $this->assertTrue($this->policy->manageAssets($user, $this->collection));
    }

    public function test_brand_manager_can_view_create_update_delete_manage_assets(): void
    {
        $user = $this->managerUser;
        $this->assertTrue($this->policy->view($user, $this->collection));
        $this->assertTrue($this->policy->create($user, $this->brand));
        $this->assertTrue($this->policy->update($user, $this->collection));
        $this->assertTrue($this->policy->delete($user, $this->collection));
        $this->assertTrue($this->policy->manageAssets($user, $this->collection));
    }

    public function test_contributor_can_view_but_cannot_modify(): void
    {
        $user = $this->contributorUser;
        $this->assertTrue($this->policy->view($user, $this->collection));
        $this->assertFalse($this->policy->create($user, $this->brand));
        $this->assertFalse($this->policy->update($user, $this->collection));
        $this->assertFalse($this->policy->delete($user, $this->collection));
        $this->assertFalse($this->policy->manageAssets($user, $this->collection));
    }

    public function test_viewer_can_view_but_cannot_modify(): void
    {
        $user = $this->viewerUser;
        $this->assertTrue($this->policy->view($user, $this->collection));
        $this->assertFalse($this->policy->create($user, $this->brand));
        $this->assertFalse($this->policy->update($user, $this->collection));
        $this->assertFalse($this->policy->delete($user, $this->collection));
        $this->assertFalse($this->policy->manageAssets($user, $this->collection));
    }

    public function test_user_not_in_brand_cannot_view_or_modify(): void
    {
        $user = $this->notInBrandUser;
        $this->assertFalse($this->policy->view($user, $this->collection));
        $this->assertFalse($this->policy->create($user, $this->brand));
        $this->assertFalse($this->policy->update($user, $this->collection));
        $this->assertFalse($this->policy->delete($user, $this->collection));
        $this->assertFalse($this->policy->manageAssets($user, $this->collection));
    }
}
