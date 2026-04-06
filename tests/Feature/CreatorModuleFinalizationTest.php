<?php

namespace Tests\Feature;

use App\Enums\EventType;
use App\Models\ActivityEvent;
use App\Models\Brand;
use App\Models\ProstaffMembership;
use App\Models\ProstaffPeriodStat;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\User;
use App\Services\GrantCreatorModuleToTenant;
use App\Services\Prostaff\AssignProstaffMember;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CreatorModuleFinalizationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Brand $brand;

    protected User $manager;

    protected User $contributor;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00', 'UTC'));

        foreach (['view brand', 'asset.view', 'asset.publish'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $this->tenant = Tenant::create(['name' => 'Finalize Co', 'slug' => 'finalize-co']);
        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Finalize Brand',
            'slug' => 'finalize-brand',
        ]);

        $this->manager = User::factory()->create();
        $this->manager->tenants()->attach($this->tenant->id, ['role' => 'admin']);
        $this->manager->brands()->attach($this->brand->id, ['role' => 'brand_manager']);
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $adminRole->syncPermissions(Permission::all());
        $this->manager->assignRole($adminRole);

        $this->contributor = User::create([
            'email' => 'fin-contrib@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'F',
            'last_name' => 'C',
        ]);
        $this->contributor->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $this->contributor->brands()->attach($this->brand->id, [
            'role' => 'contributor',
            'requires_approval' => false,
            'removed_at' => null,
        ]);
        $contribRole = Role::firstOrCreate(['name' => 'contributor', 'guard_name' => 'web']);
        $contribRole->syncPermissions(Permission::whereIn('name', ['view brand', 'asset.view', 'asset.publish'])->get());
        $this->contributor->assignRole($contribRole);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function sessionFor(User $user): array
    {
        return [
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
        ];
    }

    public function test_expired_module_returns_structured_error_on_dashboard_and_assign(): void
    {
        $this->enableCreatorModuleForTenant($this->tenant);
        app(AssignProstaffMember::class)->assign($this->contributor, $this->brand, []);

        TenantModule::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('module_key', TenantModule::KEY_CREATOR)
            ->update(['status' => 'expired']);

        $dash = $this->actingAs($this->manager)
            ->withSession($this->sessionFor($this->manager))
            ->getJson("/app/api/brands/{$this->brand->id}/prostaff/dashboard");
        $dash->assertForbidden();
        $dash->assertJsonFragment(['error' => 'creator_module_inactive', 'action' => 'upgrade']);

        $this->brand->update([
            'settings' => array_merge($this->brand->settings ?? [], [
                'creator_module_approver_user_ids' => [$this->manager->id],
            ]),
        ]);
        $extra = User::factory()->create();
        $extra->tenants()->attach($this->tenant->id, ['role' => 'member']);

        $assign = $this->actingAs($this->manager)
            ->withSession($this->sessionFor($this->manager))
            ->postJson("/app/api/brands/{$this->brand->id}/prostaff/members", [
                'email' => $extra->email,
            ]);
        $assign->assertForbidden();
        $assign->assertJsonFragment(['error' => 'creator_module_inactive']);
    }

    public function test_inertia_shared_creator_module_status_matches_entitlement(): void
    {
        $this->enableCreatorModuleForTenant($this->tenant, [
            'status' => 'trial',
            'expires_at' => now()->addDays(10),
        ]);

        $this->actingAs($this->manager)
            ->withSession($this->sessionFor($this->manager))
            ->get('/app/assets')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('creator_module_status')
                ->where('creator_module_status.enabled', true)
                ->where('creator_module_status.status', 'trial'));

        TenantModule::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('module_key', TenantModule::KEY_CREATOR)
            ->update(['status' => 'cancelled']);

        $this->actingAs($this->manager)
            ->withSession($this->sessionFor($this->manager))
            ->get('/app/assets')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('creator_module_status.enabled', false)
                ->where('creator_module_status.status', 'cancelled'));
    }

    public function test_grant_creator_module_requires_expires_at(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Admin grant requires expires_at.');

        app(GrantCreatorModuleToTenant::class)->grant($this->tenant, null, $this->manager);
    }

    public function test_audit_logs_for_grant_status_and_expiry(): void
    {
        $this->assertSame(
            0,
            ActivityEvent::query()->where('tenant_id', $this->tenant->id)->count()
        );

        app(GrantCreatorModuleToTenant::class)->grant($this->tenant, now()->addMonth(), $this->manager);

        $this->assertTrue(
            ActivityEvent::query()
                ->where('tenant_id', $this->tenant->id)
                ->where('event_type', EventType::CREATOR_MODULE_ADMIN_GRANTED)
                ->exists()
        );
        $this->assertTrue(
            ActivityEvent::query()
                ->where('tenant_id', $this->tenant->id)
                ->where('event_type', EventType::CREATOR_MODULE_ACTIVATED)
                ->exists()
        );

        $module = TenantModule::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('module_key', TenantModule::KEY_CREATOR)
            ->sole();

        $module->update(['status' => 'expired']);
        $this->assertTrue(
            ActivityEvent::query()
                ->where('tenant_id', $this->tenant->id)
                ->where('event_type', EventType::CREATOR_MODULE_EXPIRED)
                ->exists()
        );
    }

    public function test_prostaff_membership_and_stats_persist_when_module_expires(): void
    {
        $this->enableCreatorModuleForTenant($this->tenant);
        $membership = app(AssignProstaffMember::class)->assign($this->contributor, $this->brand, []);

        ProstaffPeriodStat::create([
            'prostaff_membership_id' => $membership->id,
            'period_type' => 'month',
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'target_uploads' => 10,
            'actual_uploads' => 3,
        ]);

        $mid = $membership->id;
        $this->assertSame(1, ProstaffMembership::query()->where('brand_id', $this->brand->id)->count());

        TenantModule::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('module_key', TenantModule::KEY_CREATOR)
            ->update(['status' => 'expired']);

        $this->assertSame(1, ProstaffMembership::query()->where('brand_id', $this->brand->id)->count());
        $this->assertSame(1, ProstaffPeriodStat::query()->where('prostaff_membership_id', $mid)->count());

        $opts = $this->actingAs($this->manager)
            ->withSession($this->sessionFor($this->manager))
            ->getJson("/app/api/brands/{$this->brand->id}/prostaff/options");
        $opts->assertOk();
        $this->assertSame([], $opts->json());
    }
}
