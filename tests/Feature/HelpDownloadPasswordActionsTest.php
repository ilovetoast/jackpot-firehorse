<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HelpDownloadPasswordActionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Tenant, 1: Brand, 2: User}
     */
    private function workspace(string $plan): array
    {
        $tenant = Tenant::create([
            'name' => 'T',
            'slug' => 't-dl-pw-'.md5($plan),
            'manual_plan_override' => $plan,
        ]);
        $brand = $tenant->brands()->where('is_default', true)->firstOrFail();
        $user = User::create([
            'email' => 'dl-pw-'.$plan.'@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'H',
            'last_name' => 'P',
            'email_verified_at' => now(),
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        return [$tenant, $brand, $user];
    }

    public function test_search_add_password_to_download_enterprise_returns_password_protect_first(): void
    {
        [$tenant, $brand, $user] = $this->workspace('enterprise');

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->getJson('/app/help/actions?q='.rawurlencode('add password to download'));

        $response->assertOk();
        $this->assertSame('downloads.password_protect', $response->json('results.0.key'));
    }

    public function test_search_password_protect_share_link_enterprise_returns_password_protect_first(): void
    {
        [$tenant, $brand, $user] = $this->workspace('enterprise');

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->getJson('/app/help/actions?q='.rawurlencode('password protect share link'));

        $response->assertOk();
        $this->assertSame('downloads.password_protect', $response->json('results.0.key'));
    }

    public function test_search_free_plan_returns_unavailable_topic_first(): void
    {
        [$tenant, $brand, $user] = $this->workspace('free');

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->getJson('/app/help/actions?q='.rawurlencode('add password to download'));

        $response->assertOk();
        $this->assertSame('downloads.password_protection_unavailable', $response->json('results.0.key'));
    }
}
