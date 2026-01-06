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
    const rolePermissions = auth?.role_permissions || {}
    const directPermissions = auth?.permissions || []
    
    // Normalize to array
    const permissions = Array.isArray(permission) ? permission : [permission]
    
    // Site permissions that should check direct permissions
    const sitePermissions = ['company.manage', 'permissions.manage']
    const isSitePermission = (perm) => {
        return sitePermissions.includes(perm) || perm.startsWith('site.')
    }
    
    // Memoize permission check to recalculate when auth props change
    const hasPermission = useMemo(() => {
        // Check permissions
        return permissions.every((perm) => {
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
    }, [permission, tenantRole, rolePermissions, directPermissions, requireAll])
    
    // If requireAll is false, we need to recalculate with OR logic
    if (!requireAll) {
        const hasAnyPermission = useMemo(() => {
            return permissions.some((perm) => {
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
        }, [permission, tenantRole, rolePermissions, directPermissions])
        
        return { hasPermission: hasAnyPermission }
    }
    
    return { hasPermission }
}
