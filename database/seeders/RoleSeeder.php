<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Role Seeder
 * 
 * Creates Spatie roles for the permission system.
 * 
 * IMPORTANT: This seeder creates roles that can be used for BOTH tenant-level and brand-level,
 * but the actual usage depends on context:
 * 
 * TENANT/COMPANY ROLES (Spatie roles assigned via tenant_user pivot):
 * - owner: Full company access
 * - admin: Company administration  
 * - member: Basic company membership (TENANT-LEVEL ONLY, NOT a brand role)
 * 
 * Note: Only Owner, Admin, and Member are tenant-level roles. All other roles are brand-scoped.
 * 
 * BRAND ROLES (stored as strings in brand_user.role, NOT Spatie roles):
 * - admin: Manage brand config
 * - brand_manager: Manage brand settings (Pro/Enterprise plans)
 * - contributor: Upload/edit assets
 * - viewer: Read-only access
 * 
 * DEPRECATED/LEGACY ROLES (kept for backward compatibility):
 * - manager: Legacy role (use 'admin' or 'brand_manager' instead)
 * - uploader: Legacy role (use 'contributor' instead)
 * 
 * NOTE: 'member' is a TENANT-LEVEL role only. It should NEVER be used as a brand role.
 * Brand roles use: admin, brand_manager, contributor, viewer
 */
class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Tenant/Company-level roles (Spatie roles)
        // Note: Only Owner, Admin, and Member are tenant-level roles. All other roles are brand-scoped.
        $tenantRoles = [
            [
                'name' => 'owner',
                'guard_name' => 'web',
            ],
            [
                'name' => 'admin',
                'guard_name' => 'web',
            ],
            [
                'name' => 'member',
                'guard_name' => 'web',
            ],
        ];

        // Legacy/DAM-specific roles (kept for backward compatibility)
        $legacyRoles = [
            [
                'name' => 'manager',
                'guard_name' => 'web',
            ],
            [
                'name' => 'contributor',
                'guard_name' => 'web',
            ],
            [
                'name' => 'uploader',
                'guard_name' => 'web',
            ],
            [
                'name' => 'viewer',
                'guard_name' => 'web',
            ],
            [
                'name' => 'brand_manager',
                'guard_name' => 'web',
            ],
        ];

        // Create all roles
        foreach (array_merge($tenantRoles, $legacyRoles) as $role) {
            Role::firstOrCreate([
                'name' => $role['name'],
                'guard_name' => $role['guard_name'],
            ]);
        }

        // Note: Brand roles (admin, brand_manager, contributor, viewer) are stored as strings
        // in the brand_user.role column, NOT as Spatie roles. This seeder creates Spatie roles
        // that are used for tenant-level permissions.
    }
}
