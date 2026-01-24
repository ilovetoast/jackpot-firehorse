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

        // DAM Asset permissions (tenant-scoped)
        $assetPermissions = [
            'asset.view',
            'asset.download',
            'asset.upload',
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
            $assetPermissions,
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
        // Note: 'member' role is deprecated - will be migrated to 'contributor'
        $owner = Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $manager = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $contributor = Role::firstOrCreate(['name' => 'contributor', 'guard_name' => 'web']);
        $uploader = Role::firstOrCreate(['name' => 'uploader', 'guard_name' => 'web']);
        $viewer = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);
        $brandManager = Role::firstOrCreate(['name' => 'brand_manager', 'guard_name' => 'web']);
        $member = Role::firstOrCreate(['name' => 'member', 'guard_name' => 'web']); // Deprecated - kept for migration

        // Owner: ALL permissions (full access)
        // Includes all company, asset, metadata, tag, and governance permissions
        $owner->syncPermissions(array_merge(
            $companyPermissions,
            $assetPermissions,
            $metadataPermissions,
            $tagPermissions,
            $governancePermissions,
            [
                'tickets.create',
                'tickets.reply',
                'tickets.view_tenant',
                'tickets.view_any',
                'ai.usage.view', // View AI usage status
                'assets.ai_metadata.regenerate', // Phase I: AI metadata regeneration
            ]
        ));

        // Admin: All Manager permissions + governance permissions
        // Includes metadata governance: registry.view, system.visibility.manage, tenant.visibility.manage, tenant.field.create, tenant.field.manage
        $admin->syncPermissions(array_merge(
            $companyPermissions,
            $assetPermissions,
            $metadataPermissions,
            $tagPermissions,
            $governancePermissions,
            [
                'tickets.create',
                'tickets.reply',
                'tickets.view_tenant',
                'tickets.view_any',
                'ai.usage.view', // View AI usage status
                'assets.ai_metadata.regenerate', // Phase I: AI metadata regeneration
            ]
        ));

        // Manager: All Contributor permissions + bypass approval + override automatic + bulk edit + suggestions
        $manager->syncPermissions(array_merge(
            $companyPermissions,
            $assetPermissions,
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
            // Note: Tickets permissions removed per standard DAM role definition
            // Tickets can be added separately if needed for specific tenants
        ));

        // Contributor: View, download, upload + set on upload + edit post upload + review candidates + tags
        // Upload metadata is auto-approved for contributors
        $contributor->syncPermissions(array_merge(
            $assetPermissions,
            $tagPermissions,
            [
                'metadata.set_on_upload',
                'metadata.edit_post_upload',
                'metadata.review_candidates',
            ]
            // Note: Tickets permissions removed per standard DAM role definition
            // Tickets can be added separately if needed for specific tenants
        ));

        // Uploader: View, download, upload + set metadata on upload
        $uploader->syncPermissions(array_merge(
            $assetPermissions,
            [
                'metadata.set_on_upload',
            ]
            // Note: Tickets permissions removed per standard DAM role definition
            // Tickets can be added separately if needed for specific tenants
        ));

        // Viewer: View and download only (minimal permissions)
        $viewer->syncPermissions([
            'asset.view',
            'asset.download',
            // Note: Tickets permissions removed per standard DAM role definition
            // Tickets can be added separately if needed for specific tenants
        ]);

        // Brand Manager: Legacy role - keep existing permissions + suggestions
        $brandManager->syncPermissions([
            'brand_settings.manage',
            'brand_categories.manage',
            'billing.view',
            'assets.retry_thumbnails',
            'metadata.suggestions.view',
            'metadata.suggestions.apply',
            'metadata.suggestions.dismiss',
            'tickets.create',
            'tickets.reply',
            'tickets.view_tenant',
        ]);

        // Member: Deprecated - map to Contributor permissions for backward compatibility
        // This will be migrated to 'contributor' role in a migration
        $member->syncPermissions(array_merge(
            $assetPermissions,
            [
                'metadata.set_on_upload',
                'metadata.edit_post_upload',
                'metadata.review_candidates',
            ],
            [
                'tickets.create',
                'tickets.reply',
                'tickets.view_tenant',
            ]
        ));

        // Create and assign permissions to site roles
        $siteOwner = Role::firstOrCreate(['name' => 'site_owner', 'guard_name' => 'web']);
        $siteAdmin = Role::firstOrCreate(['name' => 'site_admin', 'guard_name' => 'web']);
        $siteSupport = Role::firstOrCreate(['name' => 'site_support', 'guard_name' => 'web']);
        $siteEngineering = Role::firstOrCreate(['name' => 'site_engineering', 'guard_name' => 'web']);
        $compliance = Role::firstOrCreate(['name' => 'compliance', 'guard_name' => 'web']);

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
        
        // Compliance: View-only access (including AI Dashboard, AI Budgets, and Metadata Registry view-only)
        $compliance->syncPermissions([
            'tickets.view_staff',
            'tickets.view_engineering',
            'tickets.view_audit_log',
            'ai.dashboard.view',
            'ai.budgets.view',
            'metadata.registry.view',
        ]);
    }
}
