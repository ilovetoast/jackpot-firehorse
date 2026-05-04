<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\ImpersonationAudit;
use App\Models\ImpersonationSession;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ImpersonationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ImpersonationAdminTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Brand $brand;

    protected User $tenantAdmin;

    protected User $siteAdmin;

    protected User $siteSupport;

    protected User $siteEngineering;

    protected User $initiator;

    protected User $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Acme Corp',
            'slug' => 'acme-corp',
        ]);

        $this->brand = $this->tenant->brands()->where('is_default', true)->firstOrFail();

        $this->tenantAdmin = User::create([
            'email' => 'tenant-admin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Tenant',
            'last_name' => 'Admin',
        ]);
        $this->tenantAdmin->tenants()->attach($this->tenant->id, ['role' => 'admin']);
        $this->tenantAdmin->brands()->attach($this->brand->id, ['role' => 'admin', 'removed_at' => null]);

        foreach (['site_admin', 'site_support', 'site_engineering'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }

        $this->siteAdmin = User::create([
            'email' => 'site-admin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Site',
            'last_name' => 'Admin',
        ]);
        $this->siteAdmin->assignRole('site_admin');

        $this->siteSupport = User::create([
            'email' => 'site-support@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Site',
            'last_name' => 'Support',
        ]);
        $this->siteSupport->assignRole('site_support');

        $this->siteEngineering = User::create([
            'email' => 'site-eng@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Site',
            'last_name' => 'Eng',
        ]);
        $this->siteEngineering->assignRole('site_engineering');

        $this->initiator = User::create([
            'email' => 'initiator@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Init',
            'last_name' => 'Iator',
        ]);
        $this->initiator->tenants()->attach($this->tenant->id, ['role' => 'admin']);

        $this->target = User::create([
            'email' => 'target@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Tar',
            'last_name' => 'Get',
        ]);
        $this->target->tenants()->attach($this->tenant->id, ['role' => 'member']);
    }

    public function test_tenant_admin_cannot_access_admin_impersonation_index(): void
    {
        $this->actingAs($this->tenantAdmin)
            ->get('/app/admin/impersonation')
            ->assertForbidden();
    }

    public function test_site_support_can_view_admin_impersonation_index(): void
    {
        $this->actingAs($this->siteSupport)
            ->get('/app/admin/impersonation')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Impersonation/Index')
                ->has('stats')
                ->has('sessions'));
    }

    public function test_site_support_sees_only_own_sessions_in_index(): void
    {
        ImpersonationSession::query()->create([
            'initiator_user_id' => $this->siteAdmin->id,
            'target_user_id' => $this->target->id,
            'tenant_id' => $this->tenant->id,
            'mode' => 'read_only',
            'reason' => 'Other initiator',
            'started_at' => now()->subMinutes(10),
            'expires_at' => now()->addMinutes(20),
            'ended_at' => null,
            'ip_address' => null,
            'user_agent' => null,
        ]);

        $own = ImpersonationSession::query()->create([
            'initiator_user_id' => $this->siteSupport->id,
            'target_user_id' => $this->target->id,
            'tenant_id' => $this->tenant->id,
            'mode' => 'read_only',
            'reason' => 'Own row',
            'started_at' => now()->subMinutes(5),
            'expires_at' => now()->addMinutes(25),
            'ended_at' => null,
            'ip_address' => null,
            'user_agent' => null,
        ]);

        $this->actingAs($this->siteSupport)
            ->get('/app/admin/impersonation')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pagination.total', 1)
                ->where('sessions.0.id', $own->id));
    }

    public function test_site_engineering_cannot_access_impersonation_enter(): void
    {
        $this->actingAs($this->siteEngineering)
            ->get('/app/admin/impersonation/enter')
            ->assertForbidden();
    }

    public function test_site_support_can_view_impersonation_enter_page(): void
    {
        $this->actingAs($this->siteSupport)
            ->get('/app/admin/impersonation/enter')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Impersonation/Enter'));
    }

    public function test_site_support_can_view_own_session_detail(): void
    {
        $session = ImpersonationSession::query()->create([
            'initiator_user_id' => $this->siteSupport->id,
            'target_user_id' => $this->target->id,
            'tenant_id' => $this->tenant->id,
            'mode' => 'read_only',
            'reason' => 'Own session',
            'started_at' => now()->subMinutes(5),
            'expires_at' => now()->addMinutes(25),
            'ended_at' => null,
            'ip_address' => null,
            'user_agent' => null,
        ]);

        $this->actingAs($this->siteSupport)
            ->get('/app/admin/impersonation/'.$session->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('session.id', $session->id));
    }

    public function test_site_support_cannot_view_other_users_session_detail(): void
    {
        $session = ImpersonationSession::query()->create([
            'initiator_user_id' => $this->siteAdmin->id,
            'target_user_id' => $this->target->id,
            'tenant_id' => $this->tenant->id,
            'mode' => 'read_only',
            'reason' => 'Not yours',
            'started_at' => now()->subMinutes(5),
            'expires_at' => now()->addMinutes(25),
            'ended_at' => null,
            'ip_address' => null,
            'user_agent' => null,
        ]);

        $this->actingAs($this->siteSupport)
            ->get('/app/admin/impersonation/'.$session->id)
            ->assertForbidden();
    }

    public function test_site_support_cannot_force_end_session(): void
    {
        $session = ImpersonationSession::query()->create([
            'initiator_user_id' => $this->initiator->id,
            'target_user_id' => $this->target->id,
            'tenant_id' => $this->tenant->id,
            'mode' => 'read_only',
            'reason' => 'Force end block',
            'started_at' => now()->subMinutes(2),
            'expires_at' => now()->addMinutes(28),
            'ended_at' => null,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Test',
        ]);

        $this->actingAs($this->siteSupport)
            ->post('/app/admin/impersonation/'.$session->id.'/end')
            ->assertForbidden();
    }

    public function test_site_admin_can_view_impersonation_index(): void
    {
        $this->actingAs($this->siteAdmin)
            ->get('/app/admin/impersonation')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Impersonation/Index')
                ->has('stats')
                ->has('sessions'));
    }

    public function test_site_admin_can_view_impersonation_show(): void
    {
        $session = ImpersonationSession::query()->create([
            'initiator_user_id' => $this->initiator->id,
            'target_user_id' => $this->target->id,
            'tenant_id' => $this->tenant->id,
            'mode' => 'read_only',
            'reason' => 'Ticket ABC-1',
            'started_at' => now()->subMinutes(5),
            'expires_at' => now()->addMinutes(25),
            'ended_at' => null,
            'ip_address' => '10.0.0.1',
            'user_agent' => 'PHPUnit',
        ]);

        $this->actingAs($this->siteAdmin)
            ->get('/app/admin/impersonation/'.$session->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Impersonation/Show')
                ->where('session.id', $session->id));
    }

    public function test_active_sessions_appear_when_filtering_active(): void
    {
        ImpersonationSession::query()->create([
            'initiator_user_id' => $this->initiator->id,
            'target_user_id' => $this->target->id,
            'tenant_id' => $this->tenant->id,
            'mode' => 'read_only',
            'reason' => 'Active row',
            'started_at' => now()->subMinute(),
            'expires_at' => now()->addHour(),
            'ended_at' => null,
            'ip_address' => null,
            'user_agent' => null,
        ]);

        $this->actingAs($this->siteAdmin)
            ->get('/app/admin/impersonation?status=active')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('sessions.0'));
    }

    public function test_historical_sessions_appear_in_list(): void
    {
        $expires = now()->subHours(3);
        ImpersonationSession::query()->create([
            'initiator_user_id' => $this->initiator->id,
            'target_user_id' => $this->target->id,
            'tenant_id' => $this->tenant->id,
            'mode' => 'read_only',
            'reason' => 'Historical row',
            'started_at' => now()->subDays(2),
            'expires_at' => $expires,
            'ended_at' => $expires->copy()->subHour(),
            'ip_address' => null,
            'user_agent' => null,
        ]);

        $this->actingAs($this->siteAdmin)
            ->get('/app/admin/impersonation?status=ended')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('sessions.0'));
    }

    public function test_site_admin_can_force_end_active_session(): void
    {
        $session = ImpersonationSession::query()->create([
            'initiator_user_id' => $this->initiator->id,
            'target_user_id' => $this->target->id,
            'tenant_id' => $this->tenant->id,
            'mode' => 'read_only',
            'reason' => 'Force end test',
            'started_at' => now()->subMinutes(2),
            'expires_at' => now()->addMinutes(28),
            'ended_at' => null,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Test',
        ]);

        $before = ImpersonationAudit::query()->where('impersonation_session_id', $session->id)->count();

        $this->actingAs($this->siteAdmin)
            ->post('/app/admin/impersonation/'.$session->id.'/end')
            ->assertRedirect();

        $session->refresh();
        $this->assertNotNull($session->ended_at);

        $after = ImpersonationAudit::query()->where('impersonation_session_id', $session->id)->count();
        $this->assertGreaterThan($before, $after);

        $forced = ImpersonationAudit::query()
            ->where('impersonation_session_id', $session->id)
            ->where('event', ImpersonationAudit::EVENT_ENDED)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($forced);
        $this->assertSame('admin_forced', $forced->meta['cause'] ?? null);
        $this->assertSame($this->siteAdmin->id, (int) ($forced->meta['admin_user_id'] ?? 0));
    }

    public function test_tenant_admin_cannot_access_impersonation_enter(): void
    {
        $this->actingAs($this->tenantAdmin)
            ->get('/app/admin/impersonation/enter')
            ->assertForbidden();
    }

    public function test_site_admin_can_view_impersonation_enter_page(): void
    {
        $this->actingAs($this->siteAdmin)
            ->get('/app/admin/impersonation/enter')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Impersonation/Enter'));
    }

    public function test_site_support_can_start_read_only_without_tenant_membership(): void
    {
        $this->target->brands()->attach($this->brand->id, ['role' => 'contributor', 'removed_at' => null]);

        $this->actingAs($this->siteSupport)
            ->post(route('admin.impersonation.start'), [
                'target_user_id' => $this->target->id,
                'tenant_id' => $this->tenant->id,
                'brand_id' => $this->brand->id,
                'mode' => 'read_only',
                'reason' => 'PHPUnit site_support read-only',
            ])
            ->assertRedirect(route('app'))
            ->assertSessionHas(ImpersonationService::SESSION_KEY);

        $this->assertDatabaseHas('impersonation_sessions', [
            'initiator_user_id' => $this->siteSupport->id,
            'target_user_id' => $this->target->id,
            'tenant_id' => $this->tenant->id,
            'mode' => 'read_only',
        ]);
    }

    public function test_site_support_cannot_start_full_mode(): void
    {
        $this->target->brands()->attach($this->brand->id, ['role' => 'contributor', 'removed_at' => null]);

        $this->actingAs($this->siteSupport)
            ->post(route('admin.impersonation.start'), [
                'target_user_id' => $this->target->id,
                'tenant_id' => $this->tenant->id,
                'brand_id' => $this->brand->id,
                'mode' => 'full',
                'reason' => 'Should be rejected',
            ])
            ->assertSessionHasErrors('mode');
    }

    public function test_site_admin_can_start_full_without_tenant_membership(): void
    {
        $this->target->brands()->attach($this->brand->id, ['role' => 'contributor', 'removed_at' => null]);

        $this->actingAs($this->siteAdmin)
            ->post(route('admin.impersonation.start'), [
                'target_user_id' => $this->target->id,
                'tenant_id' => $this->tenant->id,
                'brand_id' => $this->brand->id,
                'mode' => 'full',
                'reason' => 'PHPUnit site_admin full',
            ])
            ->assertRedirect(route('app'))
            ->assertSessionHas(ImpersonationService::SESSION_KEY);

        $this->assertDatabaseHas('impersonation_sessions', [
            'initiator_user_id' => $this->siteAdmin->id,
            'mode' => 'full',
        ]);
    }

    public function test_site_admin_starts_session_audit_has_internal_support_entry(): void
    {
        $this->target->brands()->attach($this->brand->id, ['role' => 'contributor', 'removed_at' => null]);

        $this->actingAs($this->siteAdmin)
            ->post(route('admin.impersonation.start'), [
                'target_user_id' => $this->target->id,
                'tenant_id' => $this->tenant->id,
                'brand_id' => $this->brand->id,
                'mode' => 'read_only',
                'reason' => 'PHPUnit audit entry',
            ])
            ->assertRedirect(route('app'));

        $started = ImpersonationAudit::query()
            ->where('event', ImpersonationAudit::EVENT_STARTED)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($started);
        $this->assertSame('internal_support', $started->meta['entry'] ?? null);
    }

    public function test_command_center_enter_rejects_brand_target_cannot_access(): void
    {
        $otherBrand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other brand',
            'slug' => 'other-brand-cc-enter',
        ]);
        $this->target->brands()->attach($this->brand->id, ['role' => 'contributor', 'removed_at' => null]);

        $this->actingAs($this->siteAdmin)
            ->post(route('admin.impersonation.start'), [
                'target_user_id' => $this->target->id,
                'tenant_id' => $this->tenant->id,
                'brand_id' => $otherBrand->id,
                'mode' => 'read_only',
                'reason' => 'PHPUnit invalid brand',
            ])
            ->assertSessionHasErrors('brand_id');
    }
}
