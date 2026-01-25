<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Tenant Role Seeder
 * 
 * Creates tenant/company-level roles (NOT site-level roles like site_owner, site_admin).
 * 
 * Tenant/Company Roles (what a person can do at the company level):
 * - owner: Full company access
 * - admin: Company administration
 * - member: Basic company membership (tenant-level only, NOT a brand role)
 * 
 * Brand Roles (what a person can do for a brand's assets):
 * - admin: Manage brand config
 * - brand_manager: Manage brand settings (Pro/Enterprise plans)
 * - contributor: Upload/edit assets
 * - viewer: Read-only access
 * 
 * IMPORTANT:
 * - 'member' is TENANT-LEVEL ONLY, not a brand role
 * - 'owner' is TENANT-LEVEL ONLY, not a brand role
 * - Brand roles are stored in brand_user.role (string, not Spatie roles)
 * - Tenant roles are Spatie roles assigned via tenant_user pivot
 */
class TenantRoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Tenant/Company-level roles (NOT site-level)
        $tenantRoles = [
            [
                'name' => 'owner',
                'guard_name' => 'web',
                'description' => 'Full company access - can manage everything at the company level',
            ],
            [
                'name' => 'admin',
                'guard_name' => 'web',
                'description' => 'Company administration - can manage company settings and users',
            ],
            [
                'name' => 'member',
                'guard_name' => 'web',
                'description' => 'Basic company membership - tenant-level only (NOT a brand role)',
            ],
        ];

        foreach ($tenantRoles as $role) {
            Role::firstOrCreate([
                'name' => $role['name'],
                'guard_name' => $role['guard_name'],
            ]);
        }

        // Note: Brand roles (admin, brand_manager, contributor, viewer) are NOT Spatie roles
        // They are stored as strings in the brand_user.role column
        // This seeder only creates tenant-level Spatie roles
    }
}
