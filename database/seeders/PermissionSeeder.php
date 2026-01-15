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
            'view.restricted.categories',
            'assets.retry_thumbnails',
        ];

        // Site permissions (global) - for site roles only
        $sitePermissions = [
            'company.manage',
            'permissions.manage',
            // Support ticket permissions
            'tickets.view_any',
            'tickets.view_tenant',
            'tickets.create',
            'tickets.reply',
            'tickets.view_staff',
            'tickets.assign',
            'tickets.add_internal_note',
            'tickets.convert',
            'tickets.view_sla',
            'tickets.view_audit_log',
            'tickets.create_engineering',
            'tickets.view_engineering',
            'tickets.link_diagnostic',
            // AI Dashboard permissions
            'ai.dashboard.view',
            'ai.dashboard.manage',
            // AI Budget permissions
            'ai.budgets.view',
            'ai.budgets.manage',
            // Admin thumbnail regeneration (site roles only)
            'assets.regenerate_thumbnails_admin',
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

        // Owner has all company permissions + tenant-facing ticket permissions
        $owner->syncPermissions(array_merge($companyPermissions, [
            'tickets.create',
            'tickets.reply',
            'tickets.view_tenant',
            'tickets.view_any',
        ]));

        // Admin has all company permissions + tenant-facing ticket permissions
        $admin->syncPermissions(array_merge($companyPermissions, [
            'tickets.create',
            'tickets.reply',
            'tickets.view_tenant',
            'tickets.view_any',
        ]));

        // Brand Manager has brand-related permissions, billing view, basic ticket permissions, and thumbnail retry
        $brandManager->syncPermissions([
            'brand_settings.manage',
            'brand_categories.manage',
            'billing.view',
            'assets.retry_thumbnails',
            'tickets.create',
            'tickets.reply',
            'tickets.view_tenant',
        ]);

        // Member has basic ticket permissions (create, reply, view own tenant tickets)
        $member->syncPermissions([
            'tickets.create',
            'tickets.reply',
            'tickets.view_tenant',
        ]);

        // Create and assign permissions to site roles
        $siteOwner = Role::firstOrCreate(['name' => 'site_owner', 'guard_name' => 'web']);
        $siteAdmin = Role::firstOrCreate(['name' => 'site_admin', 'guard_name' => 'web']);
        $siteSupport = Role::firstOrCreate(['name' => 'site_support', 'guard_name' => 'web']);
        $siteEngineering = Role::firstOrCreate(['name' => 'site_engineering', 'guard_name' => 'web']);
        $compliance = Role::firstOrCreate(['name' => 'compliance', 'guard_name' => 'web']);

        // Site Owner has all site permissions by default
        $siteOwner->syncPermissions($sitePermissions);
        
        // Assign default ticket permissions to other roles based on their typical access
        // Site Admin: Full ticket access + AI Dashboard manage + AI Budgets manage + thumbnail regeneration
        $siteAdmin->syncPermissions([
            'tickets.view_staff',
            'tickets.assign',
            'tickets.add_internal_note',
            'tickets.convert',
            'tickets.view_sla',
            'tickets.create_engineering',
            'tickets.view_engineering',
            'tickets.link_diagnostic',
            'ai.dashboard.view',
            'ai.dashboard.manage',
            'ai.budgets.view',
            'ai.budgets.manage',
            'assets.regenerate_thumbnails_admin',
        ]);
        
        // Site Support: Can manage tenant tickets and add internal notes + thumbnail regeneration for troubleshooting
        $siteSupport->syncPermissions([
            'tickets.view_staff',
            'tickets.assign',
            'tickets.add_internal_note',
            'tickets.view_sla',
            'assets.regenerate_thumbnails_admin',
        ]);
        
        // Site Engineering: Can view and manage internal tickets + thumbnail regeneration for troubleshooting
        $siteEngineering->syncPermissions([
            'tickets.view_staff',
            'tickets.view_engineering',
            'tickets.create_engineering',
            'tickets.add_internal_note',
            'tickets.link_diagnostic',
            'tickets.view_sla',
            'assets.regenerate_thumbnails_admin',
        ]);
        
        // Compliance: View-only access (including AI Dashboard and AI Budgets view-only)
        $compliance->syncPermissions([
            'tickets.view_staff',
            'tickets.view_engineering',
            'tickets.view_audit_log',
            'ai.dashboard.view',
            'ai.budgets.view',
        ]);
    }
}
