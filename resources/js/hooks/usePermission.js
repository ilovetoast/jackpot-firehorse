import { usePage } from '@inertiajs/react'
import { useMemo } from 'react'

/**
 * Hook to check if the current user has a specific permission
 * @param {string|string[]} permission - Permission name(s) to check
 * @param {boolean} requireAll - If true, all permissions must be present (AND). If false, any permission is sufficient (OR). Default: true
 * @returns {{ hasPermission: boolean }}
 */
export function usePermission(permission, requireAll = true) {
    const { auth } = usePage().props
    
    // Extract values that affect permission calculation
    const tenantRole = auth?.tenant_role || null
    const brandRole = auth?.brand_role || null
    const rolePermissions = auth?.role_permissions || {}
    const directPermissions = auth?.permissions || []
    
    // Normalize to array
    const permissions = Array.isArray(permission) ? permission : [permission]
    
    // Site permissions that should check direct permissions
    // Also includes admin permissions that are available to site roles
    const sitePermissions = ['company.manage', 'permissions.manage', 'assets.regenerate_thumbnails_admin']
    const isSitePermission = (perm) => {
        return sitePermissions.includes(perm) || perm.startsWith('site.') || perm.endsWith('.admin')
    }
    
    // Brand-scoped permissions (permissions that should check brand role, not tenant role)
    // These are permissions defined in PermissionMap::brandPermissions()
    const brandScopedPermissions = [
        'asset.view', 'asset.download', 'asset.upload', 'asset.publish', 'asset.unpublish', 'asset.archive', 'asset.restore',
        'metadata.set_on_upload', 'metadata.edit_post_upload', 'metadata.bypass_approval', 'metadata.override_automatic',
        'metadata.review_candidates', 'metadata.bulk_edit', 'metadata.suggestions.view', 'metadata.suggestions.apply', 'metadata.suggestions.dismiss',
        'assets.tags.create', 'assets.tags.delete',
        'brand_settings.manage', 'brand_categories.manage', 'billing.view', 'assets.retry_thumbnails',
    ]
    const isBrandScopedPermission = (perm) => {
        return brandScopedPermissions.includes(perm)
    }
    
    // Memoize permission check to recalculate when auth props change
    const hasPermission = useMemo(() => {
        // Check permissions
        return permissions.every((perm) => {
            // For brand-scoped permissions, ONLY check brand role (brand-level control, not tenant-level)
            // Exception: tenant admin/owner bypass brand role checks (they have full access to all brands)
            if (isBrandScopedPermission(perm)) {
                // Tenant admin/owner bypass brand role checks (they have full access to all brands)
                // This matches backend behavior: hasPermissionForBrand allows admin/owner to bypass
                if (tenantRole && ['admin', 'owner'].includes(tenantRole)) {
                    // Admin/owner at tenant level have full access - they can do anything
                    // We assume they have the permission (matches backend hasPermissionForBrand logic)
                    return true
                }
                
                // For all other users, ONLY check brand role (brand-level control, not tenant-level)
                // This ensures brand-scoped permissions are controlled by brand role, not tenant role
                if (brandRole && typeof brandRole === 'string' && rolePermissions && typeof rolePermissions === 'object') {
                    const brandRolePerms = rolePermissions[brandRole]
                    if (Array.isArray(brandRolePerms) && brandRolePerms.includes(perm)) {
                        return true
                    }
                }
                
                // No brand role or permission not found - deny access
                // This ensures brand-scoped permissions are ONLY controlled by brand role
                return false
            }
            
            // For company permissions, ONLY check tenant role permissions (not site permissions)
            // For site permissions, check both direct permissions and tenant role
            const isSite = isSitePermission(perm)
            
            // If it's a site permission, check direct permissions first
            if (isSite && Array.isArray(directPermissions) && directPermissions.includes(perm)) {
                return true
            }
            
            // For ALL permissions (both company and site), check tenant role permissions
            // This ensures company permissions are ONLY checked via tenant role
            if (tenantRole && typeof tenantRole === 'string' && rolePermissions && typeof rolePermissions === 'object') {
                const rolePerms = rolePermissions[tenantRole]
                if (Array.isArray(rolePerms)) {
                    return rolePerms.includes(perm)
                } else {
                    // Role exists in mapping but permissions is not an array (shouldn't happen, but be defensive)
                    return false
                }
            }
            
            return false
        })
    }, [permission, tenantRole, brandRole, rolePermissions, directPermissions, requireAll])
    
    // If requireAll is false, we need to recalculate with OR logic
    if (!requireAll) {
        const hasAnyPermission = useMemo(() => {
            return permissions.some((perm) => {
                // For brand-scoped permissions, ONLY check brand role (brand-level control, not tenant-level)
                // Exception: tenant admin/owner bypass brand role checks (they have full access to all brands)
                if (isBrandScopedPermission(perm)) {
                    // Tenant admin/owner bypass brand role checks (they have full access to all brands)
                    // This matches backend behavior: hasPermissionForBrand allows admin/owner to bypass
                    if (tenantRole && ['admin', 'owner'].includes(tenantRole)) {
                        // Admin/owner at tenant level have full access - they can do anything
                        // We assume they have the permission (matches backend hasPermissionForBrand logic)
                        return true
                    }
                    
                    // For all other users, ONLY check brand role (brand-level control, not tenant-level)
                    // This ensures brand-scoped permissions are controlled by brand role, not tenant role
                    if (brandRole && typeof brandRole === 'string' && rolePermissions && typeof rolePermissions === 'object') {
                        const brandRolePerms = rolePermissions[brandRole]
                        if (Array.isArray(brandRolePerms) && brandRolePerms.includes(perm)) {
                            return true
                        }
                    }
                    
                    // No brand role or permission not found - deny access
                    // This ensures brand-scoped permissions are ONLY controlled by brand role
                    return false
                }
                
                // For company permissions, ONLY check tenant role permissions
                // For site permissions, check both direct and tenant role
                const isSite = isSitePermission(perm)
                
                // If it's a site permission, check direct permissions first
                if (isSite && Array.isArray(directPermissions) && directPermissions.includes(perm)) {
                    return true
                }
                
                // For ALL permissions, check tenant role permissions
                if (tenantRole && typeof tenantRole === 'string' && rolePermissions && typeof rolePermissions === 'object') {
                    const rolePerms = rolePermissions[tenantRole]
                    if (Array.isArray(rolePerms)) {
                        return rolePerms.includes(perm)
                    }
                }
                
                return false
            })
        }, [permission, tenantRole, brandRole, rolePermissions, directPermissions])
        
        return { hasPermission: hasAnyPermission }
    }
    
    return { hasPermission }
}
