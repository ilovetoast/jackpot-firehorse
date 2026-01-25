<?php

namespace App\Http\Controllers;

use App\Support\Roles\PermissionMap;
use App\Support\Roles\RoleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Role API Controller
 * 
 * Provides API endpoints for frontend to load canonical role lists.
 * Owner is never included in responses.
 */
class RoleController extends Controller
{
    /**
     * Get assignable tenant roles.
     * 
     * Returns roles that can be assigned via UI or invite.
     * Owner is excluded (can only be assigned via ownership transfer).
     * 
     * GET /api/roles/tenant
     */
    public function tenantRoles(): JsonResponse
    {
        $roles = collect(RoleRegistry::assignableTenantRoles())->map(function ($role) {
            return [
                'value' => $role,
                'label' => ucfirst($role),
            ];
        })->values();

        return response()->json([
            'roles' => $roles,
        ]);
    }

    /**
     * Get all brand roles.
     * 
     * Returns all valid brand roles.
     * 
     * GET /api/roles/brand
     */
    public function brandRoles(): JsonResponse
    {
        $roles = collect(RoleRegistry::brandRoles())->map(function ($role) {
            return [
                'value' => $role,
                'label' => ucfirst(str_replace('_', ' ', $role)),
            ];
        })->values();

        return response()->json([
            'roles' => $roles,
        ]);
    }

    /**
     * Get brand approver roles.
     * 
     * Returns brand roles that can approve assets.
     * 
     * GET /api/roles/brand/approvers
     */
    public function brandApproverRoles(): JsonResponse
    {
        $roles = collect(RoleRegistry::brandApproverRoles())->map(function ($role) {
            return [
                'value' => $role,
                'label' => ucfirst(str_replace('_', ' ', $role)),
            ];
        })->values();

        return response()->json([
            'roles' => $roles,
        ]);
    }

    /**
     * Get tenant role permissions.
     * 
     * Returns permissions for each assignable tenant role.
     * Owner is excluded (never assignable via UI).
     * 
     * GET /api/permissions/tenant
     */
    public function tenantPermissions(): JsonResponse
    {
        $permissions = PermissionMap::tenantPermissions();
        $roles = RoleRegistry::assignableTenantRoles(); // Excludes owner

        $result = collect($roles)->map(function ($role) use ($permissions) {
            return [
                'role' => $role,
                'permissions' => $permissions[$role] ?? [],
                'approval_capable' => false, // Tenant roles don't approve assets
            ];
        })->values();

        return response()->json([
            'roles' => $result,
        ]);
    }

    /**
     * Get brand role permissions.
     * 
     * Returns permissions for each brand role.
     * 
     * GET /api/permissions/brand
     */
    public function brandPermissions(): JsonResponse
    {
        $permissions = PermissionMap::brandPermissions();
        $roles = RoleRegistry::brandRoles();

        $result = collect($roles)->map(function ($role) use ($permissions) {
            return [
                'role' => $role,
                'permissions' => $permissions[$role] ?? [],
                'approval_capable' => PermissionMap::canApproveAssets($role),
            ];
        })->values();

        return response()->json([
            'roles' => $result,
        ]);
    }
}
