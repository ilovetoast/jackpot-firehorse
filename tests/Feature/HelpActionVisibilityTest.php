<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class HelpActionVisibilityTest extends TestCase
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
    private function baseWorkspace(): array
    {
        $tenant = Tenant::create([
            'name' => 'HV',
            'slug' => 't-help-vis',
            'manual_plan_override' => 'enterprise',
        ]);
        $brand = $tenant->brands()->where('is_default', true)->firstOrFail();

        return [$tenant, $brand];
    }

    public function test_member_does_not_see_billing_or_admin_topics_in_search(): void
    {
        [$tenant, $brand] = $this->baseWorkspace();
        $user = User::create([
            'email' => 'help-vis-member@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'M',
            'last_name' => 'B',
            'email_verified_at' => now(),
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'member']);
        $user->brands()->attach($brand->id, ['role' => 'contributor', 'removed_at' => null]);

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->getJson('/app/help/actions?q='.rawurlencode('billing plan subscription'));

        $response->assertOk();
        $keys = array_column($response->json('results'), 'key');
        $this->assertNotContains('billing.plan', $keys);
        $this->assertNotContains('billing.upgrade', $keys);
        $this->assertNotContains('tenant.metadata_registry', $keys);
    }

    public function test_generative_disabled_hides_studio_topic_in_common_and_search(): void
    {
        [$tenant, $brand] = $this->baseWorkspace();
        $tenant->settings = array_merge($tenant->settings ?? [], ['generative_enabled' => false]);
        $tenant->save();

        $user = User::create([
            'email' => 'help-vis-gen@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'O',
            'last_name' => 'W',
            'email_verified_at' => now(),
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);
        $user->syncPermissions(Permission::all());

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->getJson('/app/help/actions');

        $response->assertOk();
        $commonKeys = array_column($response->json('common'), 'key');
        $this->assertNotContains('studio.generative', $commonKeys);

        $search = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->getJson('/app/help/actions?q='.rawurlencode('studio generative'));
        $search->assertOk();
        $resultKeys = array_column($search->json('results'), 'key');
        $this->assertNotContains('studio.generative', $resultKeys);
        $this->assertContains('studio.disabled', $resultKeys);
    }

    public function test_creator_module_disabled_hides_prostaff_topics(): void
    {
        [$tenant, $brand] = $this->baseWorkspace();
        // Enterprise plan includes creator in config — force off by using starter-like behavior via settings only:
        // FeatureGate::creatorModuleEnabled is false without plan include and without tenant_modules row.
        $tenant->manual_plan_override = 'starter';
        $tenant->save();

        $user = User::create([
            'email' => 'help-vis-cr@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'O',
            'last_name' => 'W',
            'email_verified_at' => now(),
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);
        $user->syncPermissions(Permission::all());

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->getJson('/app/help/actions?q='.rawurlencode('prostaff creators tab'));

        $response->assertOk();
        $keys = array_column($response->json('results'), 'key');
        $this->assertNotContains('prostaff.creators_tab', $keys);
        $this->assertContains('creators.disabled', $keys);
    }

    public function test_serialized_related_never_includes_invisible_actions(): void
    {
        [$tenant, $brand] = $this->baseWorkspace();
        $tenant->manual_plan_override = 'starter';
        $tenant->save();

        $user = User::create([
            'email' => 'help-vis-rel@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'O',
            'last_name' => 'W',
            'email_verified_at' => now(),
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);
        $user->syncPermissions(Permission::all());

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->getJson('/app/help/actions?q='.rawurlencode('overview tasks'));

        $response->assertOk();
        $results = $response->json('results');
        $hit = collect($results)->firstWhere('key', 'overview.tasks');
        $this->assertIsArray($hit);
        $relatedKeys = array_column($hit['related'] ?? [], 'key');
        $this->assertNotContains('prostaff.creator_home', $relatedKeys);
    }

    public function test_contributor_common_topics_skip_insights_usage(): void
    {
        [$tenant, $brand] = $this->baseWorkspace();
        $user = User::create([
            'email' => 'help-vis-contr@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'C',
            'last_name' => 'T',
            'email_verified_at' => now(),
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'member']);
        $user->brands()->attach($brand->id, ['role' => 'contributor', 'removed_at' => null]);

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->getJson('/app/help/actions');

        $response->assertOk();
        $commonKeys = array_column($response->json('common'), 'key');
        $this->assertNotContains('ai.credits_usage', $commonKeys);
    }
}
