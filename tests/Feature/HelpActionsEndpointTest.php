<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HelpActionsEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_json_shape_for_authenticated_tenant_session(): void
    {
        $tenant = Tenant::create([
            'name' => 'T',
            'slug' => 't-help',
            'manual_plan_override' => 'enterprise',
        ]);
        // Use the default brand created by Tenant::created (same pattern as production gateway session).
        $brand = $tenant->brands()->where('is_default', true)->firstOrFail();
        $user = User::create([
            'email' => 'help-endpoint@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'H',
            'last_name' => 'E',
            'email_verified_at' => now(),
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->getJson('/app/help/actions');

        $response->assertOk()
            ->assertJsonStructure(['query', 'contextual', 'results', 'common']);
        $this->assertNull($response->json('query'));
        $this->assertIsArray($response->json('contextual'));
    }

    public function test_route_name_returns_contextual_matching_actions(): void
    {
        config(['help_actions.actions' => [
            [
                'key' => 'ctx_route_match',
                'title' => 'Contextual topic',
                'aliases' => [],
                'category' => 'T',
                'short_answer' => 'S',
                'steps' => [],
                'route_name' => null,
                'route_bindings' => [],
                'routes' => ['help_actions_contextual.feature_route'],
                'page_context' => [],
                'priority' => 5,
                'page_label' => 'P',
                'permissions' => [],
                'tags' => [],
                'related' => [],
                'in_common' => false,
                'common_sort' => 10,
            ],
        ]]);

        $tenant = Tenant::create([
            'name' => 'Tctx',
            'slug' => 't-help-ctx',
            'manual_plan_override' => 'enterprise',
        ]);
        $brand = $tenant->brands()->where('is_default', true)->firstOrFail();
        $user = User::create([
            'email' => 'help-ctx@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'H',
            'last_name' => 'C',
            'email_verified_at' => now(),
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->getJson('/app/help/actions?route_name='.rawurlencode('help_actions_contextual.feature_route'));

        $response->assertOk();
        $keys = array_column($response->json('contextual'), 'key');
        $this->assertContains('ctx_route_match', $keys);
    }

    public function test_non_string_q_does_not_error(): void
    {
        $tenant = Tenant::create([
            'name' => 'T2',
            'slug' => 't-help2',
            'manual_plan_override' => 'enterprise',
        ]);
        $brand = $tenant->brands()->where('is_default', true)->firstOrFail();
        $user = User::create([
            'email' => 'help-endpoint2@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'H',
            'last_name' => 'E',
            'email_verified_at' => now(),
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->getJson('/app/help/actions?q[]=x')
            ->assertOk()
            ->assertJsonPath('query', null);
    }

    public function test_guest_cannot_access_help_actions(): void
    {
        $response = $this->getJson('/app/help/actions');
        $this->assertContains($response->status(), [302, 401], 'Unauthenticated help request should redirect or reject');
    }
}
