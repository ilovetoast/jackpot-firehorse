<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class HelpActionsTerminologySearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    /**
     * @return array{0: Tenant, 1: Brand, 2: User}
     */
    private function ownerWithAllPermissions(): array
    {
        $tenant = Tenant::create([
            'name' => 'HT',
            'slug' => 't-help-term',
            'manual_plan_override' => 'enterprise',
        ]);
        $brand = $tenant->brands()->where('is_default', true)->firstOrFail();
        $user = User::create([
            'email' => 'help-term@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'H',
            'last_name' => 'T',
            'email_verified_at' => now(),
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);
        $user->syncPermissions(Permission::all());

        return [$tenant, $brand, $user];
    }

    public function test_account_vs_brand_settings_finds_concept_first(): void
    {
        [$tenant, $brand, $user] = $this->ownerWithAllPermissions();

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->getJson('/app/help/actions?q='.rawurlencode('account vs brand settings'));

        $response->assertOk();
        $this->assertSame('concepts.account_vs_brand', $response->json('results.0.key'));
    }

    public function test_multiple_brands_plan_finds_company_workspace_concept_first(): void
    {
        [$tenant, $brand, $user] = $this->ownerWithAllPermissions();

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->getJson('/app/help/actions?q='.rawurlencode('multiple brands plan'));

        $response->assertOk();
        $this->assertSame('concepts.company_workspace', $response->json('results.0.key'));
    }

    public function test_what_is_brand_insights_finds_concept_first(): void
    {
        [$tenant, $brand, $user] = $this->ownerWithAllPermissions();

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->getJson('/app/help/actions?q='.rawurlencode('what is brand insights'));

        $response->assertOk();
        $this->assertSame('concepts.brand_insights', $response->json('results.0.key'));
    }

    public function test_change_password_finds_profile_first(): void
    {
        [$tenant, $brand, $user] = $this->ownerWithAllPermissions();

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->getJson('/app/help/actions?q='.rawurlencode('where do I change my password'));

        $response->assertOk();
        $this->assertSame('account.profile', $response->json('results.0.key'));
    }

    public function test_manage_users_surfaces_company_team_or_permissions_in_top_results(): void
    {
        [$tenant, $brand, $user] = $this->ownerWithAllPermissions();

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->getJson('/app/help/actions?q='.rawurlencode('where do I manage users'));

        $response->assertOk();
        $keys = array_column($response->json('results'), 'key');
        $top = array_slice($keys, 0, 3);
        $this->assertNotEmpty(array_intersect($top, ['company.permissions_page', 'team.invite_company']));
    }

    public function test_edit_one_brand_finds_brand_settings_first(): void
    {
        [$tenant, $brand, $user] = $this->ownerWithAllPermissions();

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->getJson('/app/help/actions?q='.rawurlencode('where do I edit one brand'));

        $response->assertOk();
        $this->assertSame('brand.settings', $response->json('results.0.key'));
    }
}
