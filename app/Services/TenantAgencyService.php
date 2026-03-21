<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\TenantAgency;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TenantAgencyService
{
    /**
     * Link an agency tenant to a client tenant and grant all agency users access via RBAC.
     *
     * New users added to the agency tenant later are not auto-synced (V1). A future
     * “Sync agency users” action can re-run attach logic for the same link if needed.
     *
     * @param  array<int, array{brand_id: int, role: string}>  $brandAssignments
     */
    public function attach(
        Tenant $clientTenant,
        Tenant $agencyTenant,
        string $tenantRole,
        array $brandAssignments,
        User $actor
    ): TenantAgency {
        if ($clientTenant->id === $agencyTenant->id) {
            throw ValidationException::withMessages(['agency_tenant_id' => 'A company cannot be its own agency.']);
        }

        if (! $agencyTenant->is_agency) {
            throw ValidationException::withMessages(['agency_tenant_id' => 'Selected company is not registered as an agency.']);
        }

        if (TenantAgency::where('tenant_id', $clientTenant->id)->where('agency_tenant_id', $agencyTenant->id)->exists()) {
            throw ValidationException::withMessages(['agency_tenant_id' => 'This agency is already linked.']);
        }

        \App\Support\Roles\RoleRegistry::validateTenantRoleAssignment($tenantRole);

        $brandAssignments = $this->normalizeBrandAssignments($clientTenant, $brandAssignments);

        return DB::transaction(function () use ($clientTenant, $agencyTenant, $tenantRole, $brandAssignments, $actor) {
            $record = TenantAgency::create([
                'tenant_id' => $clientTenant->id,
                'agency_tenant_id' => $agencyTenant->id,
                'role' => $tenantRole,
                'brand_assignments' => $brandAssignments,
                'created_by' => $actor->id,
            ]);

            $agencyUsers = $agencyTenant->users()->get();
            $alreadyOnClient = DB::table('tenant_user')
                ->where('tenant_id', $clientTenant->id)
                ->whereIn('user_id', $agencyUsers->pluck('id'))
                ->pluck('user_id')
                ->flip();

            foreach ($agencyUsers as $user) {
                // Never override an existing client membership (manual invites, another agency link, etc.).
                if ($alreadyOnClient->has($user->id)) {
                    continue;
                }

                $clientTenant->users()->attach($user->id, [
                    'role' => $tenantRole,
                    'is_agency_managed' => true,
                    'agency_tenant_id' => $agencyTenant->id,
                ]);

                foreach ($brandAssignments as $ba) {
                    $brand = Brand::where('id', $ba['brand_id'])->where('tenant_id', $clientTenant->id)->first();
                    if ($brand) {
                        $user->setRoleForBrand($brand, $ba['role']);
                    }
                }
            }

            return $record;
        });
    }

    /**
     * Remove agency link and revoke agency-managed memberships only.
     *
     * Only rows with is_agency_managed = true and matching agency_tenant_id are removed;
     * direct (manual) memberships are untouched.
     */
    public function detach(TenantAgency $tenantAgency): void
    {
        $clientTenant = $tenantAgency->tenant;
        $agencyTenantId = $tenantAgency->agency_tenant_id;
        $assignments = $tenantAgency->brand_assignments ?? [];

        DB::transaction(function () use ($clientTenant, $agencyTenantId, $assignments, $tenantAgency) {
            $userIds = DB::table('tenant_user')
                ->where('tenant_id', $clientTenant->id)
                ->where('is_agency_managed', true)
                ->where('agency_tenant_id', $agencyTenantId)
                ->pluck('user_id');

            foreach ($userIds as $userId) {
                foreach ($assignments as $ba) {
                    $brandId = (int) ($ba['brand_id'] ?? 0);
                    if ($brandId <= 0) {
                        continue;
                    }
                    DB::table('brand_user')
                        ->where('user_id', $userId)
                        ->where('brand_id', $brandId)
                        ->whereNull('removed_at')
                        ->update([
                            'removed_at' => now(),
                            'updated_at' => now(),
                        ]);
                }

                $clientTenant->users()->detach($userId);
            }

            $tenantAgency->delete();
        });
    }

    /**
     * @param  array<int, array{brand_id: int, role: string}>  $brandAssignments
     * @return array<int, array{brand_id: int, role: string}>
     */
    protected function normalizeBrandAssignments(Tenant $clientTenant, array $brandAssignments): array
    {
        $normalized = [];
        foreach ($brandAssignments as $ba) {
            $bid = (int) ($ba['brand_id'] ?? 0);
            $role = strtolower((string) ($ba['role'] ?? 'contributor'));
            if ($bid <= 0) {
                continue;
            }
            $brand = Brand::where('id', $bid)->where('tenant_id', $clientTenant->id)->first();
            if (! $brand) {
                continue;
            }
            \App\Support\Roles\RoleRegistry::validateBrandRoleAssignment($role);
            $normalized[] = ['brand_id' => $brand->id, 'role' => $role];
        }

        if ($normalized === []) {
            $defaultBrand = $clientTenant->defaultBrand ?? $clientTenant->brands()->first();
            if (! $defaultBrand) {
                throw ValidationException::withMessages([
                    'brand_assignments' => 'This company has no brands. Create a brand before linking an agency.',
                ]);
            }
            $normalized[] = ['brand_id' => $defaultBrand->id, 'role' => 'contributor'];
        }

        return $normalized;
    }
}
