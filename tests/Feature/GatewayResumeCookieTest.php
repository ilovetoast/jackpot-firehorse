<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Support\GatewayResumeCookie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class GatewayResumeCookieTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_resume_cookie_yields_enter_mode_when_user_has_two_brands(): void
    {
        $tenant = Tenant::create([
            'name' => 'Acme Co',
            'slug' => 'acme-co',
        ]);

        $brandA = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'Brand A',
            'slug' => 'brand-a',
        ]);

        $brandB = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'Brand B',
            'slug' => 'brand-b',
        ]);

        $user = User::create([
            'email' => 'member@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Mem',
            'last_name' => 'Ber',
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'member']);
        $user->brands()->attach($brandA->id, ['role' => 'viewer', 'removed_at' => null]);
        $user->brands()->attach($brandB->id, ['role' => 'viewer', 'removed_at' => null]);

        $payload = [
            'uid' => (int) $user->id,
            'tid' => (int) $tenant->id,
            'bid' => (int) $brandB->id,
            'exp' => now()->addHour()->getTimestamp(),
        ];
        $cookieVal = Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brandA->id])
            ->withCookie(GatewayResumeCookie::NAME, $cookieVal)
            ->get(route('gateway'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('mode', 'enter')
                ->where('context.gateway_resume_active', true));
    }

    public function test_switch_query_shows_brand_picker_and_does_not_use_resume(): void
    {
        $tenant = Tenant::create([
            'name' => 'Acme Co 2',
            'slug' => 'acme-co-2',
        ]);

        $brandA = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'Brand A2',
            'slug' => 'brand-a2',
        ]);

        $brandB = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'Brand B2',
            'slug' => 'brand-b2',
        ]);

        $user = User::create([
            'email' => 'member2@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'M',
            'last_name' => 'B',
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'member']);
        $user->brands()->attach($brandA->id, ['role' => 'viewer', 'removed_at' => null]);
        $user->brands()->attach($brandB->id, ['role' => 'viewer', 'removed_at' => null]);

        $payload = [
            'uid' => (int) $user->id,
            'tid' => (int) $tenant->id,
            'bid' => (int) $brandB->id,
            'exp' => now()->addHour()->getTimestamp(),
        ];
        $cookieVal = Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brandA->id])
            ->withCookie(GatewayResumeCookie::NAME, $cookieVal)
            ->get(route('gateway', ['switch' => 1]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('mode', 'brand_select')
                ->where('context.gateway_resume_active', false));
    }
}
