<?php

namespace Database\Seeders;

use App\Support\Roles\PermissionMap;
use App\Support\Roles\RoleRegistry;
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
        // Company permissions (tenant-scoped) - aligned with Company Settings sections
        // company_settings.edit = Company Information | manage_download_policy = Enterprise Download Policy
        // manage_dashboard_widgets = Dashboard Widgets | manage_ai_settings = AI Settings
        // view_tag_quality = Tag Quality | ownership_transfer/delete_company = owner only
        $companyPermissions = [
            'billing.view',
            'billing.manage',
            'company_settings.view',
            'company_settings.edit',
            'company_settings.manage_download_policy',
            'company_settings.manage_dashboard_widgets',
            'company_settings.manage_ai_settings',
            'company_settings.view_tag_quality',
            'team.manage',
            'activity_logs.view',
            'brand_settings.manage',
            'brand_categories.manage',
            'view.restricted.categories',
            'assets.retry_thumbnails',
        ];

        // Owner-only (Ownership Transfer, Delete Company)
        $ownerOnlyPermissions = [
            'company_settings.ownership_transfer',
            'company_settings.delete_company',
        ];

        // DAM Asset permissions (tenant-scoped)
        $assetPermissions = [
            'asset.view',
            'asset.download',
            'asset.upload',
            'asset.publish',
            'asset.unpublish',
            'asset.archive',
            'asset.restore',
        ];
        // Delete: admins/brand_managers can delete any; managers can delete only their own files
        $assetDeletePermissions = [
            'assets.delete',      // Full delete (owner, admin, brand_manager)
            'assets.delete_own',  // Delete own files only (manager)
        ];

        // DAM Metadata permissions (tenant-scoped)
        $metadataPermissions = [
            'metadata.set_on_upload',
            'metadata.edit_post_upload',
            'metadata.bypass_approval',
            'metadata.override_automatic',
            'metadata.review_candidates',
            'metadata.bulk_edit',
            // AI Metadata Suggestions permissions
            'metadata.suggestions.view',
            'metadata.suggestions.apply',
            'metadata.suggestions.dismiss',
            // AI Metadata Generation permissions (Phase I)
            'assets.ai_metadata.regenerate',
            // Metadata Field Management permissions
            'metadata.fields.manage',
            'metadata.fields.values.manage',
            // AI Usage viewing (admin only)
            'ai.usage.view',
        ];

        // DAM Tag permissions (tenant-scoped) - Phase J.2.3+
        $tagPermissions = [
            'assets.tags.create',
            'assets.tags.delete',
        ];

        // DAM Governance permissions (tenant-scoped)
        $governancePermissions = [
            'tenant.manage_settings',
            'user.manage_roles',
            // Phase C: Metadata governance permissions
            'metadata.registry.view',
            // Note: metadata.system.visibility.manage is site-level, not tenant-level
            'metadata.tenant.visibility.manage',
            'metadata.tenant.field.create',
            'metadata.tenant.field.manage',
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
            // Metadata Registry permissions (site-level only - tenant version is in governancePermissions)
            // Note: metadata.registry.view exists in both site and governance for different contexts
            // Phase C1: System metadata registry view (site-level for admin dashboard)
            'metadata.registry.view',
            // Phase C1 Step 2: System-level metadata visibility management
            'metadata.system.visibility.manage',
            // Admin thumbnail regeneration (site roles only)
            'assets.regenerate_thumbnails_admin',
        ];

        // Create all permissions
        $allCompanyPermissions = array_merge(
            $companyPermissions,
            $ownerOnlyPermissions,
            $assetPermissions,
            $assetDeletePermissions,
            $metadataPermissions,
            $tagPermissions,
            $governancePermissions
        );
        foreach (array_merge($allCompanyPermissions, $sitePermissions) as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        // Create and assign permissions to company roles
        // Use PermissionMap for canonical roles from RoleRegistry
        $tenantPermissions = PermissionMap::tenantPermissions();
        
        // Owner: ALL permissions (full access) - from PermissionMap
        $owner = Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
        $owner->syncPermissions($tenantPermissions['owner']);

        // Admin: All Manager permissions + governance permissions - from PermissionMap
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin->syncPermissions($tenantPermissions['admin']);

        // Member: Basic company membership - from PermissionMap
        $member = Role::firstOrCreate(['name' => 'member', 'guard_name' => 'web']);
        $member->syncPermissions($tenantPermissions['member']);

        // Legacy roles (kept for backward compatibility, not in RoleRegistry)
        $manager = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $contributor = Role::firstOrCreate(['name' => 'contributor', 'guard_name' => 'web']);
        $uploader = Role::firstOrCreate(['name' => 'uploader', 'guard_name' => 'web']);
        $viewer = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);
        $brandManager = Role::firstOrCreate(['name' => 'brand_manager', 'guard_name' => 'web']);

        // Legacy roles - use PermissionMap for canonical brand roles where applicable
        $brandPermissions = PermissionMap::brandPermissions();

        // Manager: All Contributor permissions + bypass approval + override automatic + bulk edit + suggestions
        // Can delete only their own files (assets.delete_own), not others'
        $manager->syncPermissions(array_merge(
            $companyPermissions,
            $assetPermissions,
            ['assets.delete_own'],
            $tagPermissions,
            [
                'metadata.set_on_upload',
                'metadata.edit_post_upload',
                'metadata.bypass_approval',
                'metadata.override_automatic',
                'metadata.review_candidates',
                'metadata.bulk_edit',
                'metadata.suggestions.view',
                'metadata.suggestions.apply',
                'metadata.suggestions.dismiss',
            ]
        ));

        // Contributor: Use PermissionMap for canonical brand role
        $contributor->syncPermissions($brandPermissions['contributor']);

        // Uploader: View, download, upload + set metadata on upload
        // Legacy role - keep existing behavior
        $uploader->syncPermissions(array_merge(
            $assetPermissions,
            [
                'metadata.set_on_upload',
            ]
        ));

        // Viewer: Use PermissionMap for canonical brand role
        $viewer->syncPermissions($brandPermissions['viewer']);

        // Brand Manager: Use PermissionMap for canonical brand role
        $brandManager->syncPermissions($brandPermissions['brand_manager']);

        // Note: Brand 'admin' role also exists as Spatie role for backward compatibility
        // It gets permissions from PermissionMap::brandPermissions()['admin']
        $brandAdmin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        // Don't sync here - 'admin' is already synced above as tenant role
        // Brand admin permissions are handled via brand_user.role string, not Spatie role

        // Create and assign permissions to site roles
        $siteOwner = Role::firstOrCreate(['name' => 'site_owner', 'guard_name' => 'web']);
        $siteAdmin = Role::firstOrCreate(['name' => 'site_admin', 'guard_name' => 'web']);
        $siteSupport = Role::firstOrCreate(['name' => 'site_support', 'guard_name' => 'web']);
        $siteEngineering = Role::firstOrCreate(['name' => 'site_engineering', 'guard_name' => 'web']);
        $siteCompliance = Role::firstOrCreate(['name' => 'site_compliance', 'guard_name' => 'web']);

        // Site Owner has all site permissions by default
        $siteOwner->syncPermissions($sitePermissions);
        
        // Note: metadata.registry.view is included in $sitePermissions, so site_owner gets it automatically
        
        // Assign default ticket permissions to other roles based on their typical access
        // Site Admin: Full ticket access + AI Dashboard manage + AI Budgets manage + thumbnail regeneration + metadata registry + metadata visibility management
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
            'metadata.registry.view',
            'metadata.system.visibility.manage',
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
        
        // Site Compliance: View-only access (including AI Dashboard, AI Budgets, and Metadata Registry view-only)
        $siteCompliance->syncPermissions([
            'tickets.view_staff',
            'tickets.view_engineering',
            'tickets.view_audit_log',
            'ai.dashboard.view',
            'ai.budgets.view',
            'metadata.registry.view',
        ]);

        // Note: Tenant-level 'compliance' role has been removed.
        // Keep only: Owner, Admin, Member for tenant-level roles.
        // All other roles should be brand-scoped.
    }
}
