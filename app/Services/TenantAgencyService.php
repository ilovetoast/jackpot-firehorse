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
     * Agency staff who already have any membership on the client are skipped (direct invites
     * and other links are not overwritten). Use {@see syncUsersForLink} to add staff who joined
     * the agency later, and {@see convertDirectMemberToAgencyManaged} when the client chooses
     * to move someone from direct to agency-managed for a linked agency.
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

        \App\Support\Roles\RoleRegistry::validateAgencyRelationshipRoleAssignment($tenantRole);

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
                if ($alreadyOnClient->has($user->id)) {
                    continue;
                }

                $this->grantAgencyUserOnClient($clientTenant, $agencyTenant, $user, $tenantRole, $brandAssignments);
            }

            return $record;
        });
    }

    /**
     * For an existing agency–client link: add client memberships for agency users who are not
     * on the client yet (same rules as initial attach — existing client members are skipped).
     *
     * @return array{added: int, skipped_existing_membership: int}
     */
    public function syncUsersForLink(TenantAgency $tenantAgency): array
    {
        $clientTenant = $tenantAgency->tenant;
        $agencyTenant = $tenantAgency->agencyTenant;

        if (! $agencyTenant || ! $agencyTenant->is_agency) {
            throw ValidationException::withMessages(['tenant_agency' => 'Invalid agency link.']);
        }

        $tenantRole = strtolower((string) $tenantAgency->role);
        \App\Support\Roles\RoleRegistry::validateAgencyRelationshipRoleAssignment($tenantRole);

        $brandAssignments = $this->normalizeBrandAssignments($clientTenant, $tenantAgency->brand_assignments ?? []);

        return DB::transaction(function () use ($clientTenant, $agencyTenant, $tenantRole, $brandAssignments) {
            $agencyUsers = $agencyTenant->users()->get();
            $alreadyOnClient = DB::table('tenant_user')
                ->where('tenant_id', $clientTenant->id)
                ->whereIn('user_id', $agencyUsers->pluck('id'))
                ->pluck('user_id')
                ->flip();

            $added = 0;
            $skippedExistingMembership = 0;

            foreach ($agencyUsers as $user) {
                if ($alreadyOnClient->has($user->id)) {
                    $skippedExistingMembership++;

                    continue;
                }

                $this->grantAgencyUserOnClient($clientTenant, $agencyTenant, $user, $tenantRole, $brandAssignments);
                $added++;
            }

            return [
                'added' => $added,
                'skipped_existing_membership' => $skippedExistingMembership,
            ];
        });
    }

    /**
     * Client admin: turn a direct (non–agency-managed) member into agency-managed for a linked agency.
     * Updates the single tenant_user row and applies the link’s company role and brand assignments
     * (same as the agency link configuration).
     */
    public function convertDirectMemberToAgencyManaged(
        Tenant $clientTenant,
        User $subjectUser,
        int $agencyTenantId,
    ): void {
        $agencyTenant = Tenant::find($agencyTenantId);
        if (! $agencyTenant || ! $agencyTenant->is_agency) {
            throw ValidationException::withMessages(['agency_tenant_id' => 'Invalid agency.']);
        }

        $tenantAgency = TenantAgency::where('tenant_id', $clientTenant->id)
            ->where('agency_tenant_id', $agencyTenantId)
            ->first();

        if (! $tenantAgency) {
            throw ValidationException::withMessages(['agency_tenant_id' => 'That agency is not linked to this company.']);
        }

        if (! $clientTenant->users()->where('users.id', $subjectUser->id)->exists()) {
            throw ValidationException::withMessages(['user_id' => 'User is not a member of this company.']);
        }

        if ($clientTenant->isOwner($subjectUser)) {
            throw ValidationException::withMessages(['user_id' => 'The company owner cannot be switched to agency-managed access.']);
        }

        $pivot = DB::table('tenant_user')
            ->where('tenant_id', $clientTenant->id)
            ->where('user_id', $subjectUser->id)
            ->first();

        if (! $pivot) {
            throw ValidationException::withMessages(['user_id' => 'Membership not found.']);
        }

        if ((bool) ($pivot->is_agency_managed ?? false)) {
            if ((int) ($pivot->agency_tenant_id ?? 0) === (int) $agencyTenantId) {
                return;
            }

            throw ValidationException::withMessages([
                'user_id' => 'This member is already managed by another agency link. Remove that link first or detach the other agency.',
            ]);
        }

        $tenantRole = strtolower((string) $tenantAgency->role);
        \App\Support\Roles\RoleRegistry::validateAgencyRelationshipRoleAssignment($tenantRole);
        $brandAssignments = $this->normalizeBrandAssignments($clientTenant, $tenantAgency->brand_assignments ?? []);

        DB::transaction(function () use ($clientTenant, $agencyTenant, $subjectUser, $tenantRole, $brandAssignments) {
            $clientTenant->users()->updateExistingPivot($subjectUser->id, [
                'role' => $tenantRole,
                'is_agency_managed' => true,
                'agency_tenant_id' => $agencyTenant->id,
            ]);

            foreach ($brandAssignments as $ba) {
                $brand = Brand::where('id', $ba['brand_id'])->where('tenant_id', $clientTenant->id)->first();
                if ($brand) {
                    $subjectUser->setRoleForBrand($brand, $ba['role']);
                }
            }
        });
    }

    /**
     * Update partnership relationship role and brand template; reapplies to agency-managed members.
     */
    public function updatePartnershipLink(
        TenantAgency $tenantAgency,
        string $newTenantRole,
        array $brandAssignmentsRaw,
        User $actor,
    ): void {
        $clientTenant = $tenantAgency->tenant;
        $agencyTenant = $tenantAgency->agencyTenant;
        if (! $agencyTenant || ! $agencyTenant->is_agency) {
            throw ValidationException::withMessages(['tenant_agency' => 'Invalid agency link.']);
        }

        $assignable = \App\Support\Roles\RoleRegistry::assignableAgencyRelationshipRolesForInviter($actor, $clientTenant, $agencyTenant);
        $newTenantRole = strtolower($newTenantRole);
        if ($assignable === [] || ! in_array($newTenantRole, $assignable, true)) {
            throw ValidationException::withMessages([
                'role' => 'You do not have permission to set this agency partnership role.',
            ]);
        }
        \App\Support\Roles\RoleRegistry::validateAgencyRelationshipRoleAssignment($newTenantRole);
        $brandAssignments = $this->normalizeBrandAssignments($clientTenant, $brandAssignmentsRaw);

        DB::transaction(function () use ($tenantAgency, $newTenantRole, $brandAssignments, $clientTenant, $agencyTenant) {
            $tenantAgency->update([
                'role' => $newTenantRole,
                'brand_assignments' => $brandAssignments,
            ]);

            $userIds = DB::table('tenant_user')
                ->where('tenant_id', $clientTenant->id)
                ->where('is_agency_managed', true)
                ->where('agency_tenant_id', $agencyTenant->id)
                ->pluck('user_id');

            foreach ($userIds as $userId) {
                DB::table('tenant_user')
                    ->where('tenant_id', $clientTenant->id)
                    ->where('user_id', $userId)
                    ->update(['role' => $newTenantRole]);

                $user = User::find($userId);
                if (! $user) {
                    continue;
                }
                foreach ($brandAssignments as $ba) {
                    $brand = Brand::where('id', $ba['brand_id'])->where('tenant_id', $clientTenant->id)->first();
                    if ($brand) {
                        $user->setRoleForBrand($brand, $ba['role']);
                    }
                }
            }
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
     * @param  array<int, array{brand_id: int, role: string}>  $brandAssignmentsNormalized
     */
    protected function grantAgencyUserOnClient(
        Tenant $clientTenant,
        Tenant $agencyTenant,
        User $user,
        string $tenantRole,
        array $brandAssignmentsNormalized
    ): void {
        $clientTenant->users()->attach($user->id, [
            'role' => $tenantRole,
            'is_agency_managed' => true,
            'agency_tenant_id' => $agencyTenant->id,
        ]);

        foreach ($brandAssignmentsNormalized as $ba) {
            $brand = Brand::where('id', $ba['brand_id'])->where('tenant_id', $clientTenant->id)->first();
            if ($brand) {
                $user->setRoleForBrand($brand, $ba['role']);
            }
        }
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
