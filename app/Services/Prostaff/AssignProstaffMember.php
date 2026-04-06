<?php

namespace App\Services\Prostaff;

use App\Models\Brand;
use App\Models\ProstaffMembership;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\User;
use App\Support\Roles\RoleRegistry;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AssignProstaffMember
{
    public function __construct(
        private EnsureCreatorModuleEnabled $ensureCreatorModuleEnabled
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *                                      Keys: target_uploads?, period_type?, period_start?, assigned_by_user_id?, custom_fields?
     */
    public function assign(User $user, Brand $brand, array $data = []): ProstaffMembership
    {
        $tenant = Tenant::query()->findOrFail($brand->tenant_id);

        $this->ensureCreatorModuleEnabled->assertEnabled($tenant);

        $existing = ProstaffMembership::query()
            ->where('brand_id', $brand->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing !== null && $existing->status === 'active') {
            return $existing->fresh() ?? $existing;
        }

        $this->assertAssignableAsProstaff($user, $tenant, $brand);

        $this->assertWithinCreatorSeatsIfApplicable($tenant, $user);

        if (! $user->belongsToTenant($tenant->id)) {
            $user->tenants()->attach($tenant->id, ['role' => 'member']);
            $user->forgetTenantRoleCacheForTenant($tenant);
        }

        $user->setRoleForBrand($brand, 'contributor');
        $user->forgetActiveBrandMembershipForBrand($brand);

        $payload = [
            'tenant_id' => $tenant->id,
            'status' => 'active',
            'target_uploads' => $data['target_uploads'] ?? null,
            'period_type' => $data['period_type'] ?? null,
            'period_start' => $data['period_start'] ?? null,
            'assigned_by_user_id' => $data['assigned_by_user_id'] ?? null,
            'custom_fields' => $data['custom_fields'] ?? null,
            'requires_approval' => true,
            'started_at' => now(),
            'ended_at' => null,
        ];

        /** @var ProstaffMembership $membership */
        $membership = ProstaffMembership::query()->updateOrCreate(
            [
                'brand_id' => $brand->id,
                'user_id' => $user->id,
            ],
            $payload
        );

        $user->forgetActiveBrandMembershipForBrand($brand);

        return $membership->fresh() ?? $membership;
    }

    /**
     * Soft enforcement: optional {@see TenantModule::$seats_limit} counts distinct active prostaff users per tenant.
     */
    private function assertWithinCreatorSeatsIfApplicable(Tenant $tenant, User $user): void
    {
        $limit = TenantModule::query()
            ->where('tenant_id', $tenant->id)
            ->where('module_key', TenantModule::KEY_CREATOR)
            ->value('seats_limit');

        if ($limit === null || (int) $limit <= 0) {
            return;
        }

        if (ProstaffMembership::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists()) {
            return;
        }

        $used = (int) ProstaffMembership::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->selectRaw('count(distinct user_id) as aggregate')
            ->value('aggregate');

        if ($used >= (int) $limit) {
            Log::warning('Creator seats limit reached; prostaff assign blocked', [
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'seats_limit' => (int) $limit,
                'distinct_active_users' => $used,
            ]);

            throw new DomainException('Creator seats limit reached.');
        }
    }

    private function assertAssignableAsProstaff(User $user, Tenant $tenant, Brand $brand): void
    {
        $tenantRole = $user->getRoleForTenant($tenant);
        if ($tenantRole !== null && $tenantRole !== '') {
            $tenantRoleLower = strtolower($tenantRole);
            if (in_array($tenantRoleLower, ['owner', 'admin'], true)) {
                throw new DomainException('Tenant owners and admins cannot be prostaff.');
            }
        }

        $pivot = DB::table('brand_user')
            ->where('user_id', $user->id)
            ->where('brand_id', $brand->id)
            ->whereNull('removed_at')
            ->first();

        if ($pivot) {
            $role = $pivot->role ?? '';
            if ($role !== '' && RoleRegistry::isBrandApproverRole($role)) {
                throw new DomainException('Brand admins and brand managers cannot be prostaff.');
            }
        }
    }
}
