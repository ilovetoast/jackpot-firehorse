<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\ImpersonationAudit;
use App\Models\ImpersonationSession;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ImpersonationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Brand $brand;

    protected User $tenantAdmin;

    protected User $adminB;

    protected User $member;

    protected User $siteSupport;

    protected User $siteAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Acme',
            'slug' => 'acme',
        ]);

        $this->brand = $this->tenant->brands()->where('is_default', true)->firstOrFail();

        foreach (['site_admin', 'site_support'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }

        $this->tenantAdmin = User::create([
            'email' => 'admin-a@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Admin',
            'last_name' => 'A',
        ]);
        $this->tenantAdmin->tenants()->attach($this->tenant->id, ['role' => 'admin']);
        $this->tenantAdmin->brands()->attach($this->brand->id, ['role' => 'admin', 'removed_at' => null]);

        $this->adminB = User::create([
            'email' => 'admin-b@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Admin',
            'last_name' => 'B',
        ]);
        $this->adminB->tenants()->attach($this->tenant->id, ['role' => 'admin']);
        $this->adminB->brands()->attach($this->brand->id, ['role' => 'admin', 'removed_at' => null]);

        $this->member = User::create([
            'email' => 'member@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Member',
            'last_name' => 'M',
        ]);
        $this->member->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $this->member->brands()->attach($this->brand->id, ['role' => 'viewer', 'removed_at' => null]);

        $this->siteSupport = User::create([
            'email' => 'support@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Site',
            'last_name' => 'Support',
        ]);
        $this->siteSupport->assignRole('site_support');

        $this->siteAdmin = User::create([
            'email' => 'site-admin-test@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Site',
            'last_name' => 'AdminT',
        ]);
        $this->siteAdmin->assignRole('site_admin');
    }

    public function test_legacy_impersonation_start_route_is_disabled(): void
    {
        $this->actingAs($this->tenantAdmin)
            ->withSession([
                'tenant_id' => $this->tenant->id,
                'brand_id' => $this->brand->id,
            ])
            ->post(route('impersonation.start'), [
                'target_user_id' => $this->adminB->id,
                'mode' => 'read_only',
                'reason' => 'Should not work from Team anymore',
            ])
            ->assertForbidden();
    }

    public function test_member_cannot_use_legacy_start_route(): void
    {
        $this->actingAs($this->member)
            ->withSession([
                'tenant_id' => $this->tenant->id,
                'brand_id' => $this->brand->id,
            ])
            ->post(route('impersonation.start'), [
                'target_user_id' => $this->tenantAdmin->id,
                'mode' => 'read_only',
                'reason' => 'Trying to escalate',
            ])
            ->assertForbidden();
    }

    public function test_team_page_does_not_offer_view_as(): void
    {
        $this->actingAs($this->tenantAdmin)
            ->withSession([
                'tenant_id' => $this->tenant->id,
                'brand_id' => $this->brand->id,
            ])
            ->get(route('companies.team'))
            ->assertOk()
            ->assertDontSee('View as this user', false);
    }

    public function test_read_only_blocks_collection_create_even_when_target_can_create(): void
    {
        $start = $this->actingAs($this->siteSupport)
            ->post(route('admin.impersonation.start'), [
                'target_user_id' => $this->adminB->id,
                'tenant_id' => $this->tenant->id,
                'brand_id' => $this->brand->id,
                'mode' => 'read_only',
                'reason' => 'Support ticket 12345 — reproduce visibility issue',
            ]);
        $start->assertRedirect(route('app'));
        $start->assertSessionHas(ImpersonationService::SESSION_KEY);

        $this->assertDatabaseHas('impersonation_sessions', [
            'initiator_user_id' => $this->siteSupport->id,
            'target_user_id' => $this->adminB->id,
            'tenant_id' => $this->tenant->id,
            'mode' => 'read_only',
        ]);

        $this->postJson('/app/collections', [
            'name' => 'Blocked Collection '.uniqid(),
        ])->assertStatus(403);
    }

    public function test_full_mode_allows_writes_for_site_admin(): void
    {
        $this->actingAs($this->siteAdmin)
            ->post(route('admin.impersonation.start'), [
                'target_user_id' => $this->adminB->id,
                'tenant_id' => $this->tenant->id,
                'brand_id' => $this->brand->id,
                'mode' => 'full',
                'reason' => 'Escalation INC-99 — reproduce admin-only workflow',
            ])
            ->assertRedirect(route('app'));

        $this->postJson('/app/collections', [
            'name' => 'Full Mode Collection '.uniqid(),
        ])->assertCreated();
    }

    public function test_expired_session_is_cleared_and_redirects(): void
    {
        $this->travelTo(Carbon::parse('2020-06-15 12:00:00'));

        try {
            $row = ImpersonationSession::query()->create([
                'initiator_user_id' => $this->siteSupport->id,
                'target_user_id' => $this->adminB->id,
                'tenant_id' => $this->tenant->id,
                'mode' => 'read_only',
                'reason' => 'test',
                'ticket_id' => null,
                'started_at' => Carbon::parse('2020-06-15 10:00:00'),
                'expires_at' => Carbon::parse('2020-06-15 10:30:00'),
                'ended_at' => null,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PHPUnit',
            ]);

            $response = $this->actingAs($this->siteSupport)
                ->withSession([
                    'tenant_id' => $this->tenant->id,
                    'brand_id' => $this->brand->id,
                    ImpersonationService::SESSION_KEY => $row->id,
                    ImpersonationService::SESSION_INITIATOR_KEY => $this->siteSupport->id,
                ])
                ->get(route('app'));

            $row->refresh();
            $this->assertNotNull($row->ended_at, 'expired impersonation must be ended in the database');
            $this->assertNull(session(ImpersonationService::SESSION_KEY));
            $this->assertTrue(
                in_array($response->status(), [200, 302], true),
                'expected overview response after expiry handling; got '.$response->status()
            );
            if ($response->isRedirect()) {
                $response->assertRedirect(route('app'));
            }
        } finally {
            $this->travelBack();
        }
    }

    public function test_audit_logs_on_start_and_request(): void
    {
        ImpersonationAudit::query()->delete();

        $this->actingAs($this->siteSupport)
            ->post(route('admin.impersonation.start'), [
                'target_user_id' => $this->adminB->id,
                'tenant_id' => $this->tenant->id,
                'brand_id' => $this->brand->id,
                'mode' => 'read_only',
                'reason' => 'Audit trail verification',
            ])
            ->assertRedirect(route('app'));

        $sessionId = (int) ImpersonationSession::query()->latest('id')->value('id');
        $this->assertGreaterThan(0, ImpersonationAudit::query()->where('impersonation_session_id', $sessionId)->where('event', ImpersonationAudit::EVENT_STARTED)->count());

        $this->get(route('app'));

        $this->assertGreaterThan(0, ImpersonationAudit::query()->where('impersonation_session_id', $sessionId)->where('event', ImpersonationAudit::EVENT_REQUEST)->count());
    }

    public function test_ticket_id_is_persisted_and_in_start_audit_meta(): void
    {
        ImpersonationAudit::query()->delete();

        $this->actingAs($this->siteSupport)
            ->post(route('admin.impersonation.start'), [
                'target_user_id' => $this->adminB->id,
                'tenant_id' => $this->tenant->id,
                'brand_id' => $this->brand->id,
                'mode' => 'read_only',
                'reason' => 'Reproduce visibility — customer approved',
                'ticket_id' => 'SF-10042',
            ])
            ->assertRedirect(route('app'));

        $this->assertDatabaseHas('impersonation_sessions', [
            'initiator_user_id' => $this->siteSupport->id,
            'target_user_id' => $this->adminB->id,
            'ticket_id' => 'SF-10042',
        ]);

        $sessionId = (int) ImpersonationSession::query()->latest('id')->value('id');
        $started = ImpersonationAudit::query()
            ->where('impersonation_session_id', $sessionId)
            ->where('event', ImpersonationAudit::EVENT_STARTED)
            ->first();
        $this->assertNotNull($started);
        $this->assertSame('SF-10042', $started->meta['ticket_id'] ?? null);
    }
}
