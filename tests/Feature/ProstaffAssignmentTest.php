<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\ProstaffMembership;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Prostaff\AssignProstaffMember;
use App\Services\Prostaff\RemoveProstaffMember;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProstaffAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Brand $brand;

    protected User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Co',
            'slug' => 'co-assign',
        ]);

        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'B1',
            'slug' => 'b1-assign',
        ]);

        $this->enableCreatorModuleForTenant($this->tenant);

        $this->actor = User::create([
            'email' => 'actor@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'A',
            'last_name' => 'C',
        ]);
        $this->actor->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $this->actor->brands()->attach($this->brand->id, [
            'role' => 'admin',
            'requires_approval' => false,
            'removed_at' => null,
        ]);
    }

    public function test_assign_creates_tenant_user_when_missing(): void
    {
        $user = User::create([
            'email' => 'floating@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'F',
            'last_name' => 'L',
        ]);

        $membership = app(AssignProstaffMember::class)->assign($user, $this->brand, [
            'assigned_by_user_id' => $this->actor->id,
            'target_uploads' => 5,
        ]);

        $this->assertTrue($user->fresh()->belongsToTenant($this->tenant->id));
        $this->assertSame('member', $user->fresh()->getRoleForTenant($this->tenant));

        $pivot = DB::table('brand_user')
            ->where('user_id', $user->id)
            ->where('brand_id', $this->brand->id)
            ->whereNull('removed_at')
            ->first();
        $this->assertNotNull($pivot);
        $this->assertSame('contributor', $pivot->role);

        $this->assertSame('active', $membership->status);
        $this->assertSame(5, $membership->target_uploads);
        $this->assertSame($this->actor->id, $membership->assigned_by_user_id);
        $this->assertNotNull($membership->started_at);
    }

    public function test_assign_creates_brand_user_when_missing_on_tenant(): void
    {
        $user = User::create([
            'email' => 'tenantonly@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'T',
            'last_name' => 'O',
        ]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'member']);

        app(AssignProstaffMember::class)->assign($user, $this->brand, []);

        $exists = DB::table('brand_user')
            ->where('user_id', $user->id)
            ->where('brand_id', $this->brand->id)
            ->whereNull('removed_at')
            ->exists();
        $this->assertTrue($exists);
    }

    public function test_assign_forces_contributor_when_user_was_viewer(): void
    {
        $user = User::create([
            'email' => 'viewer@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'V',
            'last_name' => 'W',
        ]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $user->brands()->attach($this->brand->id, [
            'role' => 'viewer',
            'requires_approval' => false,
            'removed_at' => null,
        ]);

        app(AssignProstaffMember::class)->assign($user, $this->brand, []);

        $role = DB::table('brand_user')
            ->where('user_id', $user->id)
            ->where('brand_id', $this->brand->id)
            ->whereNull('removed_at')
            ->value('role');
        $this->assertSame('contributor', $role);
    }

    public function test_cannot_assign_tenant_admin(): void
    {
        $user = User::create([
            'email' => 'tadmin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'T',
            'last_name' => 'A',
        ]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'admin']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Tenant owners and admins');

        app(AssignProstaffMember::class)->assign($user, $this->brand, []);
    }

    public function test_cannot_assign_tenant_owner(): void
    {
        $user = User::create([
            'email' => 'owner@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'O',
            'last_name' => 'W',
        ]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'owner']);

        $this->expectException(DomainException::class);

        app(AssignProstaffMember::class)->assign($user, $this->brand, []);
    }

    public function test_cannot_assign_brand_manager(): void
    {
        $user = User::create([
            'email' => 'bmassign@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'B',
            'last_name' => 'M',
        ]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $user->brands()->attach($this->brand->id, [
            'role' => 'brand_manager',
            'requires_approval' => false,
            'removed_at' => null,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Brand admins and brand managers');

        app(AssignProstaffMember::class)->assign($user, $this->brand, []);
    }

    public function test_active_brand_membership_requires_approval_true_for_prostaff_runtime_only(): void
    {
        $user = User::create([
            'email' => 'proapprover@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'P',
            'last_name' => 'A',
        ]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $user->brands()->attach($this->brand->id, [
            'role' => 'contributor',
            'requires_approval' => false,
            'removed_at' => null,
        ]);

        app(AssignProstaffMember::class)->assign($user, $this->brand, []);

        $fresh = $user->fresh();
        $membership = $fresh->activeBrandMembership($this->brand);
        $this->assertNotNull($membership);
        $this->assertTrue($membership['requires_approval']);

        $pivotRequires = DB::table('brand_user')
            ->where('user_id', $user->id)
            ->where('brand_id', $this->brand->id)
            ->whereNull('removed_at')
            ->value('requires_approval');
        $this->assertFalse((bool) $pivotRequires);
    }

    public function test_remove_sets_status_removed_and_keeps_row(): void
    {
        $user = User::create([
            'email' => 'removeme@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'R',
            'last_name' => 'M',
        ]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $user->brands()->attach($this->brand->id, [
            'role' => 'contributor',
            'requires_approval' => true,
            'removed_at' => null,
        ]);

        $membership = app(AssignProstaffMember::class)->assign($user, $this->brand, []);
        $id = $membership->id;

        app(RemoveProstaffMember::class)->remove($membership);

        $row = ProstaffMembership::query()->find($id);
        $this->assertNotNull($row);
        $this->assertSame('removed', $row->status);
        $this->assertNotNull($row->ended_at);
        $this->assertFalse($user->fresh()->isProstaffForBrand($this->brand));
    }

    public function test_second_assign_when_already_active_does_not_overwrite_targets(): void
    {
        $user = User::create([
            'email' => 'idempotent@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'I',
            'last_name' => 'D',
        ]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $user->brands()->attach($this->brand->id, [
            'role' => 'contributor',
            'requires_approval' => true,
            'removed_at' => null,
        ]);

        $assign = app(AssignProstaffMember::class);
        $first = $assign->assign($user, $this->brand, [
            'target_uploads' => 10,
            'period_type' => 'month',
            'assigned_by_user_id' => $this->actor->id,
        ]);
        $startedAt = $first->started_at;

        $second = $assign->assign($user, $this->brand, [
            'target_uploads' => 999,
            'period_type' => 'year',
            'assigned_by_user_id' => $this->actor->id,
        ]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(10, $second->target_uploads);
        $this->assertSame('month', $second->period_type);
        $this->assertTrue($startedAt?->equalTo($second->started_at));
    }
}
