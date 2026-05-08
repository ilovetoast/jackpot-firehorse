<?php

namespace Tests\Concerns;

use App\Models\Brand;
use App\Models\BrandOnboardingProgress;
use App\Models\Tenant;
use App\Models\User;

/**
 * Fixture for gateway/manage routes: verified admin on the tenant's default brand,
 * with onboarding marked activated.
 *
 * Creating a second Brand for the tenant can exceed single-brand plan limits; the
 * session brand is then treated as disabled and EnsureGatewayEntry may 302.
 */
trait CreatesActivatedTenantBrandAdmin
{
    /**
     * @param  array<string, mixed>  $tenantAttributes
     * @param  array<string, mixed>  $userAttributes  merged into User::create after defaults
     * @return array{0: Tenant, 1: Brand, 2: User}
     */
    protected function createActivatedTenantBrandAdmin(
        array $tenantAttributes,
        array $userAttributes = []
    ): array {
        $tenant = Tenant::create($tenantAttributes);
        $brand = $tenant->fresh()->brands()->where('is_default', true)->first()
            ?? $tenant->brands()->firstOrFail();

        $user = User::create(array_merge([
            'email' => 'activated-admin@example.test',
            'password' => bcrypt('password'),
            'first_name' => 'A',
            'last_name' => 'U',
        ], $userAttributes));

        if (! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $user->tenants()->attach($tenant->id, ['role' => 'admin']);
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        BrandOnboardingProgress::query()->updateOrCreate(
            ['brand_id' => $brand->id],
            ['tenant_id' => $tenant->id, 'activated_at' => now()]
        );

        return [$tenant, $brand, $user];
    }

    protected function actingAsTenantBrand(User $user, Tenant $tenant, Brand $brand): static
    {
        return $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id]);
    }
}
