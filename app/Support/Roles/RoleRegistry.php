<?php

namespace App\Support\Roles;

use App\Models\Tenant;
use App\Models\User;

/**
 * Canonical Role Registry
 *
 * Single source of truth for all tenant and brand roles.
 * All role lists must come from this registry - no hardcoded arrays.
 *
 * ABSOLUTE RULES:
 * - Owner must never be assignable via UI or invite
 * - No automatic role conversion
 * - All validation must reference this registry
 * - Invalid role assignments must return 422 errors
 */
class RoleRegistry
{
    /**
     * Get all tenant roles (including owner).
     *
     * @return array<string> All tenant role names
     */
    public static function tenantRoles(): array
    {
        return ['owner', 'admin', 'member', 'agency_partner', 'agency_admin'];
    }

    /**
     * Get assignable tenant roles (excludes owner).
     * Owner can only be assigned via ownership transfer flow.
     *
     * Phase AG-5: Added agency_partner (assigned via transfer, revocable by client)
     *
     * @return array<string> Assignable tenant role names
     */
    public static function assignableTenantRoles(): array
    {
        return ['admin', 'member', 'agency_partner', 'agency_admin'];
    }

    /**
     * Direct company membership roles (Company → Team, non–agency-relationship).
     *
     * @return list<string>
     */
    public static function directCompanyTenantRoles(): array
    {
        return ['admin', 'member'];
    }

    /**
     * Client-side agency partnership grants only (stored on {@see \App\Models\TenantAgency} and
     * agency-managed {@code tenant_user} rows). Never use for generic Company → Team invite.
     *
     * @return list<string>
     */
    public static function agencyRelationshipRoles(): array
    {
        return ['agency_partner', 'agency_admin'];
    }

    /**
     * Human-readable label for a tenant role key (UI). Keys stay snake_case internally.
     */
    public static function tenantRoleDisplayLabel(string $role): string
    {
        return match (strtolower($role)) {
            'owner' => 'Owner',
            'admin' => 'Admin',
            'member' => 'Member',
            'agency_admin' => 'Agency manager',
            'agency_partner' => 'Agency partner',
            default => ucfirst(str_replace('_', ' ', strtolower($role))),
        };
    }

    /**
     * @return array<string, string> role key => display label
     */
    public static function tenantRoleLabelsKeyed(): array
    {
        $out = [];
        foreach (self::tenantRoles() as $role) {
            $out[$role] = self::tenantRoleDisplayLabel($role);
        }

        return $out;
    }

    /**
     * Roles a user may assign for **direct** company invites (Company → Team).
     * Excludes partnership roles ({@see agencyRelationshipRoles()}) and owner.
     *
     * @return list<string>
     */
    public static function directAssignableTenantRolesForInviter(User $inviter, Tenant $tenant): array
    {
        $r = strtolower((string) $inviter->getRoleForTenant($tenant));

        if (in_array($r, ['owner', 'admin'], true)) {
            return self::directCompanyTenantRoles();
        }

        if ($r === 'agency_admin') {
            return ['member'];
        }

        return ['member'];
    }

    /**
     * Partnership relationship roles a client inviter may set when linking/managing an agency.
     *
     * @return list<string>
     */
    public static function assignableAgencyRelationshipRolesForInviter(User $inviter, Tenant $clientTenant, ?Tenant $agencyTenant = null): array
    {
        $r = strtolower((string) $inviter->getRoleForTenant($clientTenant));
        if (in_array($r, ['owner', 'admin'], true)) {
            return self::agencyRelationshipRoles();
        }

        return [];
    }

    /**
     * Roles assignable on the **agency tenant** workspace (agency staff), not client partnership keys.
     *
     * @return list<string>
     */
    public static function assignableAgencyWorkspaceRolesForInviter(User $inviter, Tenant $agencyTenant): array
    {
        if (! $agencyTenant->is_agency) {
            return [];
        }

        return self::directAssignableTenantRolesForInviter($inviter, $agencyTenant);
    }

    /**
     * @deprecated Use {@see directAssignableTenantRolesForInviter()} for invite UI.
     *
     * @return list<string>
     */
    public static function assignableTenantRolesForInviter(string $inviterRole): array
    {
        $r = strtolower($inviterRole);

        if (in_array($r, ['owner', 'admin'], true)) {
            return self::directCompanyTenantRoles();
        }

        if ($r === 'agency_admin') {
            return ['member'];
        }

        return ['member'];
    }

    /**
     * Get all brand roles.
     *
     * @return array<string> All brand role names
     */
    public static function brandRoles(): array
    {
        return ['admin', 'brand_manager', 'contributor', 'viewer'];
    }

    /**
     * Get brand roles that can approve assets.
     *
     * Approval rules:
     * - admin and brand_manager can approve assets
     * - contributor and viewer cannot approve
     *
     * @return array<string> Brand approver role names
     */
    public static function brandApproverRoles(): array
    {
        return ['admin', 'brand_manager'];
    }

    /**
     * Brand roles that may see all team downloads for that brand (Downloads "All" scope, filters).
     * Aligned with approvers: admin + brand_manager.
     *
     * @return array<string>
     */
    public static function brandRolesWithTeamDownloadVisibility(): array
    {
        return ['admin', 'brand_manager'];
    }

    /**
     * Check if a role is a valid tenant role.
     *
     * @param  string  $role  Role name to check
     * @return bool True if valid tenant role
     */
    public static function isValidTenantRole(string $role): bool
    {
        return in_array(strtolower($role), self::tenantRoles(), true);
    }

    /**
     * Check if a role is an assignable tenant role.
     *
     * @param  string  $role  Role name to check
     * @return bool True if assignable tenant role
     */
    public static function isAssignableTenantRole(string $role): bool
    {
        return in_array(strtolower($role), self::assignableTenantRoles(), true);
    }

    /**
     * Check if a role is a valid brand role.
     *
     * @param  string  $role  Role name to check
     * @return bool True if valid brand role
     */
    public static function isValidBrandRole(string $role): bool
    {
        return in_array(strtolower($role), self::brandRoles(), true);
    }

    /**
     * Check if a role is a brand approver role.
     *
     * @param  string  $role  Role name to check
     * @return bool True if brand approver role
     */
    public static function isBrandApproverRole(string $role): bool
    {
        return in_array(strtolower($role), self::brandApproverRoles(), true);
    }

    public static function isAgencyRelationshipRole(string $role): bool
    {
        return in_array(strtolower($role), self::agencyRelationshipRoles(), true);
    }

    public static function isDirectCompanyTenantRole(string $role): bool
    {
        return in_array(strtolower($role), self::directCompanyTenantRoles(), true);
    }

    /**
     * Validate **direct** company role (admin/member only) for Company → Team invite and role updates.
     *
     * @throws \InvalidArgumentException
     */
    public static function validateDirectCompanyTenantRoleAssignment(string $role): void
    {
        $role = strtolower($role);

        if ($role === 'owner') {
            throw new \InvalidArgumentException(
                'Owner cannot be assigned via team invite or the team role dropdown. Use ownership transfer.'
            );
        }

        if (! self::isDirectCompanyTenantRole($role)) {
            throw new \InvalidArgumentException(
                "Invalid company role for direct team management: {$role}. Allowed roles: ".implode(', ', self::directCompanyTenantRoles()).'.'
            );
        }
    }

    /**
     * Validate partnership relationship role for TenantAgency / agency-managed provisioning only.
     *
     * @throws \InvalidArgumentException
     */
    public static function validateAgencyRelationshipRoleAssignment(string $role): void
    {
        $role = strtolower($role);

        if (! self::isValidTenantRole($role)) {
            throw new \InvalidArgumentException(
                "Invalid tenant role: {$role}. Valid roles are: ".implode(', ', self::tenantRoles())
            );
        }

        if (! self::isAgencyRelationshipRole($role)) {
            throw new \InvalidArgumentException(
                "Role '{$role}' is not an agency partnership role. Use ".implode(' or ', self::agencyRelationshipRoles()).' for agency links.'
            );
        }
    }

    /**
     * Validate tenant role assignment.
     *
     * @param  string  $role  Role name to validate
     *
     * @throws \InvalidArgumentException If role is invalid or not assignable
     */
    public static function validateTenantRoleAssignment(string $role): void
    {
        $role = strtolower($role);

        if (! self::isValidTenantRole($role)) {
            throw new \InvalidArgumentException(
                "Invalid tenant role: {$role}. Valid roles are: ".implode(', ', self::tenantRoles())
            );
        }

        if (! self::isAssignableTenantRole($role)) {
            throw new \InvalidArgumentException(
                "Role '{$role}' cannot be assigned via UI or invite. Owner role can only be assigned via ownership transfer."
            );
        }
    }

    /**
     * Validate brand role assignment.
     *
     * @param  string  $role  Role name to validate
     *
     * @throws \InvalidArgumentException If role is invalid
     */
    public static function validateBrandRoleAssignment(string $role): void
    {
        $role = strtolower($role);

        if (! self::isValidBrandRole($role)) {
            throw new \InvalidArgumentException(
                "Invalid brand role: {$role}. Valid roles are: ".implode(', ', self::brandRoles())
            );
        }
    }
}
