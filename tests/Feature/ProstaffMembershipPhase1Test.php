<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\ProstaffMembership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProstaffMembershipPhase1Test extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Brand $brand;

    protected User $contributor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Co',
            'slug' => 'co',
        ]);

        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'B1',
            'slug' => 'b1',
        ]);

        $this->contributor = User::create([
            'email' => 'creator@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'C',
            'last_name' => 'R',
        ]);
        $this->contributor->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $this->contributor->brands()->attach($this->brand->id, [
            'role' => 'contributor',
            'requires_approval' => true,
            'removed_at' => null,
        ]);
    }

    public function test_creates_membership_when_rules_satisfied_and_helpers_resolve(): void
    {
        $membership = ProstaffMembership::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->contributor->id,
            'status' => 'active',
            'requires_approval' => true,
            'custom_fields' => ['jersey' => '12'],
        ]);

        $this->assertSame($this->contributor->id, $membership->user_id);
        $this->assertSame(['jersey' => '12'], $membership->custom_fields);

        $this->assertTrue(
            $this->brand->prostaffMembers()->where('users.id', $this->contributor->id)->exists()
        );

        $fromUser = $this->contributor->prostaffMembershipForBrand($this->brand);
        $this->assertNotNull($fromUser);
        $this->assertSame($membership->id, $fromUser->id);
    }

    public function test_duplicate_brand_user_rejected_by_database(): void
    {
        ProstaffMembership::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->contributor->id,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        ProstaffMembership::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->contributor->id,
        ]);
    }

    public function test_rejects_mismatched_tenant_id(): void
    {
        $other = Tenant::create(['name' => 'T2', 'slug' => 't2']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("brand's tenant_id");

        ProstaffMembership::create([
            'tenant_id' => $other->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->contributor->id,
        ]);
    }

    public function test_rejects_without_active_brand_user(): void
    {
        $lonely = User::create([
            'email' => 'lonely@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'L',
            'last_name' => 'Y',
        ]);
        $lonely->tenants()->attach($this->tenant->id, ['role' => 'member']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('active brand_user');

        ProstaffMembership::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $lonely->id,
        ]);
    }

    public function test_rejects_user_not_on_tenant(): void
    {
        $outsider = User::create([
            'email' => 'out@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'O',
            'last_name' => 'T',
        ]);
        $outsider->brands()->attach($this->brand->id, [
            'role' => 'contributor',
            'removed_at' => null,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('belong to the brand\'s tenant');

        ProstaffMembership::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $outsider->id,
        ]);
    }

    public function test_rejects_brand_admin(): void
    {
        $admin = User::create([
            'email' => 'badmin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'B',
            'last_name' => 'A',
        ]);
        $admin->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $admin->brands()->attach($this->brand->id, [
            'role' => 'admin',
            'removed_at' => null,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('admin or brand_manager');

        ProstaffMembership::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $admin->id,
        ]);
    }

    public function test_rejects_brand_manager(): void
    {
        $bm = User::create([
            'email' => 'bm@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'B',
            'last_name' => 'M',
        ]);
        $bm->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $bm->brands()->attach($this->brand->id, [
            'role' => 'brand_manager',
            'removed_at' => null,
        ]);

        $this->expectException(\InvalidArgumentException::class);

        ProstaffMembership::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $bm->id,
        ]);
    }

    public function test_rejects_viewer_brand_role(): void
    {
        $viewer = User::create([
            'email' => 'viewer@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'V',
            'last_name' => 'W',
        ]);
        $viewer->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $viewer->brands()->attach($this->brand->id, [
            'role' => 'viewer',
            'removed_at' => null,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('contributor');

        ProstaffMembership::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $viewer->id,
        ]);
    }
}
