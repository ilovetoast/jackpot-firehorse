<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Company permissions (tenant-scoped)
        $companyPermissions = [
            'billing.view',
            'billing.manage',
            'company_settings.view',
            'team.manage',
            'activity_logs.view',
            'brand_settings.manage',
            'brand_categories.manage',
        ];

        // Site permissions (global)
        $sitePermissions = [
            'company.manage',
            'permissions.manage',
        ];

        // Create all permissions
        foreach (array_merge($companyPermissions, $sitePermissions) as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        // Create and assign permissions to company roles
        $owner = Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $brandManager = Role::firstOrCreate(['name' => 'brand_manager', 'guard_name' => 'web']);
        $member = Role::firstOrCreate(['name' => 'member', 'guard_name' => 'web']);

        // Owner has all company permissions
        $owner->syncPermissions($companyPermissions);

        // Admin has all company permissions
        $admin->syncPermissions($companyPermissions);

        // Brand Manager has brand-related permissions and billing view
        $brandManager->syncPermissions([
            'brand_settings.manage',
            'brand_categories.manage',
            'billing.view',
        ]);

        // Member has no special permissions (basic access only)
        $member->syncPermissions([]);

        // Create and assign permissions to site roles
        $siteOwner = Role::firstOrCreate(['name' => 'site_owner', 'guard_name' => 'web']);
        $siteAdmin = Role::firstOrCreate(['name' => 'site_admin', 'guard_name' => 'web']);
        $siteSupport = Role::firstOrCreate(['name' => 'site_support', 'guard_name' => 'web']);
        $compliance = Role::firstOrCreate(['name' => 'compliance', 'guard_name' => 'web']);

        // Site Owner has all site permissions
        $siteOwner->syncPermissions($sitePermissions);

        // Site Admin, Site Support, and Compliance start with no permissions (can be assigned via UI)
        $siteAdmin->syncPermissions([]);
        $siteSupport->syncPermissions([]);
        $compliance->syncPermissions([]);
    }
}
