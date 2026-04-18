<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected User $verifiedUser;
    protected User $unverifiedUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Company',
            'slug' => 'test-co',
        ]);

        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);

        $this->verifiedUser = User::create([
            'email' => 'verified@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Verified',
            'last_name' => 'User',
            'email_verified_at' => now(),
        ]);
        $this->verifiedUser->tenants()->attach($this->tenant->id, ['role' => 'admin']);
        $this->verifiedUser->brands()->attach($this->brand->id, ['role' => 'admin', 'removed_at' => null]);

        // Unverified user that is the tenant *owner* — free-plan fresh signup
        // scenario, so the gate should keep blocking them.
        $this->unverifiedUser = User::create([
            'email' => 'unverified@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Unverified',
            'last_name' => 'User',
        ]);
        $this->unverifiedUser->tenants()->attach($this->tenant->id, ['role' => 'owner']);
        $this->unverifiedUser->brands()->attach($this->brand->id, ['role' => 'admin', 'removed_at' => null]);
    }

    private function bindBrand(): void
    {
        app()->instance('brand', $this->brand);
    }

    public function test_unverified_user_redirected_to_verify_email(): void
    {
        $this->bindBrand();

        $response = $this->actingAs($this->unverifiedUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->get('/app/assets');

        $response->assertRedirect('/app/verify-email');
    }

    public function test_verified_but_unactivated_user_redirected_to_onboarding(): void
    {
        $this->bindBrand();

        $response = $this->actingAs($this->verifiedUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->get('/app/assets');

        $response->assertRedirect('/app/onboarding');
    }

    public function test_activated_user_can_access_app(): void
    {
        $this->bindBrand();

        $service = new OnboardingService();
        $progress = $service->getOrCreateProgress($this->brand);
        $progress->update([
            'brand_name_confirmed' => true,
            'primary_color_set' => true,
            'brand_mark_confirmed' => true,
            'brand_mark_type' => 'monogram',
            'activated_at' => now(),
        ]);

        $response = $this->actingAs($this->verifiedUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->get('/app/assets');

        $response->assertStatus(200);
    }

    public function test_dismissed_user_not_blocked_from_app(): void
    {
        $this->bindBrand();

        $service = new OnboardingService();
        $service->dismissCinematicFlow($this->brand);

        $response = $this->actingAs($this->verifiedUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->get('/app/assets');

        $response->assertStatus(200);
    }

    public function test_onboarding_route_always_accessible(): void
    {
        $this->bindBrand();

        $response = $this->actingAs($this->verifiedUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->get('/app/onboarding');

        $response->assertStatus(200);
    }

    public function test_overview_route_always_accessible(): void
    {
        $this->bindBrand();

        $response = $this->actingAs($this->verifiedUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->get('/app/overview');

        $response->assertStatus(200);
    }

    public function test_profile_route_always_accessible(): void
    {
        $this->bindBrand();

        $response = $this->actingAs($this->verifiedUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->get('/app/profile');

        $response->assertOk();
    }

    public function test_json_api_requests_bypass_onboarding_gate(): void
    {
        $this->bindBrand();

        $response = $this->actingAs($this->verifiedUser)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->getJson('/app/api/overview/hero');

        $response->assertStatus(200);
    }

    /**
     * Unverified non-owner member of a paid tenant — e.g. the client user an
     * agency added and transferred ownership of later. They should slip past
     * the verification gate and land inside the app.
     */
    public function test_unverified_non_owner_on_paid_tenant_skips_verification(): void
    {
        $this->tenant->update(['manual_plan_override' => 'business']);

        $unverifiedMember = User::create([
            'email' => 'member@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Paid',
            'last_name' => 'Member',
        ]);
        $unverifiedMember->tenants()->attach($this->tenant->id, ['role' => 'admin']);
        $unverifiedMember->brands()->attach($this->brand->id, ['role' => 'admin', 'removed_at' => null]);

        $service = new OnboardingService();
        $progress = $service->getOrCreateProgress($this->brand);
        $progress->update([
            'brand_name_confirmed' => true,
            'primary_color_set' => true,
            'brand_mark_confirmed' => true,
            'brand_mark_type' => 'monogram',
            'activated_at' => now(),
        ]);

        $this->bindBrand();

        $response = $this->actingAs($unverifiedMember)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->get('/app/assets');

        $response->assertStatus(200);
    }
}
