<?php

namespace App\Support\Roles;

/**
 * Canonical Permission Map
 * 
 * Maps roles to permissions explicitly.
 * This is the single source of truth for role-to-permission mappings.
 * 
 * IMPORTANT:
 * This map applies ONLY to tenant-level and brand-level roles.
 * 
 * Site-wide roles (site_owner, site_admin, site_support, etc.)
 * are intentionally excluded and managed separately.
 * 
 * STRICT RULES:
 * - Only reference roles from RoleRegistry
 * - Do NOT rename existing permissions
 * - Do NOT delete permissions
 * - Do NOT change locked-phase behavior
 * 
 * USAGE:
 * - PermissionSeeder reads from this map
 * - API endpoints expose this map
 * - UI renders from API responses
 * - Documentation references this map
 */
class PermissionMap
{
    /**
     * Get tenant role permissions.
     * 
     * Maps tenant roles (from RoleRegistry) to their permissions.
     * 
     * @return array<string, array<string>> Role name => array of permission strings
     */
    public static function tenantPermissions(): array
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
            'asset.publish',
            'asset.unpublish',
            'asset.archive',
            'asset.restore',
        ];

        // DAM Metadata permissions (tenant-scoped)
        $metadataPermissions = [
            'metadata.set_on_upload',
            'metadata.edit_post_upload',
            'metadata.bypass_approval',
            'metadata.override_automatic',
            'metadata.review_candidates',
            'metadata.bulk_edit',
            'metadata.suggestions.view',
            'metadata.suggestions.apply',
            'metadata.suggestions.dismiss',
            'assets.ai_metadata.regenerate',
            'metadata.fields.manage',
            'metadata.fields.values.manage',
            'ai.usage.view',
        ];

        // DAM Tag permissions (tenant-scoped)
        $tagPermissions = [
            'assets.tags.create',
            'assets.tags.delete',
        ];

        // DAM Governance permissions (tenant-scoped)
        $governancePermissions = [
            'tenant.manage_settings',
            'user.manage_roles',
            'metadata.registry.view',
            'metadata.tenant.visibility.manage',
            'metadata.tenant.field.create',
            'metadata.tenant.field.manage',
        ];

        // Ticket permissions (tenant-facing)
        $ticketPermissions = [
            'tickets.create',
            'tickets.reply',
            'tickets.view_tenant',
            'tickets.view_any',
        ];

        return [
            // Owner: ALL permissions (full access)
            'owner' => array_merge(
                $companyPermissions,
                $assetPermissions,
                $metadataPermissions,
                $tagPermissions,
                $governancePermissions,
                $ticketPermissions,
                [
                    'ai.usage.view',
                    'assets.ai_metadata.regenerate',
                ]
            ),

            // Admin: All Manager permissions + governance permissions
            'admin' => array_merge(
                $companyPermissions,
                $assetPermissions,
                $metadataPermissions,
                $tagPermissions,
                $governancePermissions,
                $ticketPermissions,
                [
                    'ai.usage.view',
                    'assets.ai_metadata.regenerate',
                ]
            ),

            // Member: Basic company membership (minimal permissions)
            // Asset permissions + basic metadata + tickets
            'member' => array_merge(
                $assetPermissions,
                $tagPermissions,
                [
                    'metadata.set_on_upload',
                    'metadata.edit_post_upload',
                    'metadata.review_candidates',
                ],
                $ticketPermissions
            ),

            // Agency Partner: Retained access after company transfer
            // Phase AG-5: Can view/upload/download assets, cannot manage billing/team/settings
            'agency_partner' => array_merge(
                $assetPermissions,
                $tagPermissions,
                [
                    'metadata.set_on_upload',
                    'metadata.edit_post_upload',
                    'metadata.review_candidates',
                    'metadata.suggestions.view',
                    'metadata.suggestions.apply',
                    'metadata.suggestions.dismiss',
                ],
                $ticketPermissions
            ),
        ];
    }

    /**
     * Get brand role permissions.
     * 
     * Maps brand roles (from RoleRegistry) to their permissions.
     * Note: Brand roles are stored as strings in brand_user.role, not Spatie roles.
     * Some brand roles also exist as Spatie roles for backward compatibility.
     * 
     * @return array<string, array<string>> Role name => array of permission strings
     */
    public static function brandPermissions(): array
    {
        // Asset permissions
        $assetPermissions = [
            'asset.view',
            'asset.download',
            'asset.upload',
            'asset.publish',
            'asset.unpublish',
            'asset.archive',
            'asset.restore',
        ];

        // Metadata permissions
        $metadataPermissions = [
            'metadata.set_on_upload',
            'metadata.edit_post_upload',
            'metadata.bypass_approval',
            'metadata.override_automatic',
            'metadata.review_candidates',
            'metadata.bulk_edit',
            'metadata.suggestions.view',
            'metadata.suggestions.apply',
            'metadata.suggestions.dismiss',
        ];

        // Tag permissions
        $tagPermissions = [
            'assets.tags.create',
            'assets.tags.delete',
        ];

        // Brand management permissions
        $brandManagementPermissions = [
            'brand_settings.manage',
            'brand_categories.manage',
            'billing.view',
            'assets.retry_thumbnails',
        ];

        return [
            // Admin: Manage brand config (full brand control)
            'admin' => array_merge(
                $brandManagementPermissions,
                $assetPermissions,
                $metadataPermissions,
                $tagPermissions,
                [
                    'tickets.create',
                    'tickets.reply',
                    'tickets.view_tenant',
                ]
            ),

            // Brand Manager: Manage brand settings (can approve assets)
            // Phase M-1: Brand Manager edits bypass approval (asset-centric workflow)
            'brand_manager' => array_merge(
                $brandManagementPermissions,
                [
                    'metadata.bypass_approval', // Phase M-1: Brand Manager edits bypass approval
                    'metadata.suggestions.view',
                    'metadata.suggestions.apply',
                    'metadata.suggestions.dismiss',
                    'tickets.create',
                    'tickets.reply',
                    'tickets.view_tenant',
                ]
            ),

            // Contributor: Upload/edit assets (cannot approve)
            'contributor' => array_merge(
                $assetPermissions,
                $tagPermissions,
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
            ),

            // Viewer: Read-only access
            'viewer' => [
                'asset.view',
                'asset.download',
                'tickets.create',
                'tickets.reply',
                'tickets.view_tenant',
            ],
        ];
    }

    /**
     * Check if a brand role can approve assets.
     * 
     * Approval rules:
     * - admin and brand_manager can approve assets
     * - contributor and viewer cannot approve
     * 
     * @param string $role Brand role name
     * @return bool True if role can approve assets
     */
    public static function canApproveAssets(string $role): bool
    {
        return RoleRegistry::isBrandApproverRole($role);
    }

    /**
     * Get all permissions for a tenant role.
     * 
     * @param string $role Tenant role name
     * @return array<string> Array of permission strings
     */
    public static function getTenantRolePermissions(string $role): array
    {
        $permissions = self::tenantPermissions();
        return $permissions[strtolower($role)] ?? [];
    }

    /**
     * Get all permissions for a brand role.
     * 
     * @param string $role Brand role name
     * @return array<string> Array of permission strings
     */
    public static function getBrandRolePermissions(string $role): array
    {
        $permissions = self::brandPermissions();
        return $permissions[strtolower($role)] ?? [];
    }
}
