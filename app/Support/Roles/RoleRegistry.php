<?php

namespace App\Support\Roles;

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
        return ['owner', 'admin', 'member', 'agency_partner'];
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
        return ['admin', 'member', 'agency_partner'];
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
     * Check if a role is a valid tenant role.
     * 
     * @param string $role Role name to check
     * @return bool True if valid tenant role
     */
    public static function isValidTenantRole(string $role): bool
    {
        return in_array(strtolower($role), self::tenantRoles(), true);
    }

    /**
     * Check if a role is an assignable tenant role.
     * 
     * @param string $role Role name to check
     * @return bool True if assignable tenant role
     */
    public static function isAssignableTenantRole(string $role): bool
    {
        return in_array(strtolower($role), self::assignableTenantRoles(), true);
    }

    /**
     * Check if a role is a valid brand role.
     * 
     * @param string $role Role name to check
     * @return bool True if valid brand role
     */
    public static function isValidBrandRole(string $role): bool
    {
        return in_array(strtolower($role), self::brandRoles(), true);
    }

    /**
     * Check if a role is a brand approver role.
     * 
     * @param string $role Role name to check
     * @return bool True if brand approver role
     */
    public static function isBrandApproverRole(string $role): bool
    {
        return in_array(strtolower($role), self::brandApproverRoles(), true);
    }

    /**
     * Validate tenant role assignment.
     * 
     * @param string $role Role name to validate
     * @return void
     * @throws \InvalidArgumentException If role is invalid or not assignable
     */
    public static function validateTenantRoleAssignment(string $role): void
    {
        $role = strtolower($role);
        
        if (!self::isValidTenantRole($role)) {
            throw new \InvalidArgumentException(
                "Invalid tenant role: {$role}. Valid roles are: " . implode(', ', self::tenantRoles())
            );
        }
        
        if (!self::isAssignableTenantRole($role)) {
            throw new \InvalidArgumentException(
                "Role '{$role}' cannot be assigned via UI or invite. Owner role can only be assigned via ownership transfer."
            );
        }
    }

    /**
     * Validate brand role assignment.
     * 
     * @param string $role Role name to validate
     * @return void
     * @throws \InvalidArgumentException If role is invalid
     */
    public static function validateBrandRoleAssignment(string $role): void
    {
        $role = strtolower($role);
        
        if (!self::isValidBrandRole($role)) {
            throw new \InvalidArgumentException(
                "Invalid brand role: {$role}. Valid roles are: " . implode(', ', self::brandRoles())
            );
        }
    }
}
