import { usePermission } from '../hooks/usePermission'
import { usePage } from '@inertiajs/react'

/**
 * Component that conditionally renders children based on permission check
 * @param {string|string[]} permission - Permission name(s) to check
 * @param {boolean} requireAll - If true, all permissions must be present (AND). If false, any permission is sufficient (OR). Default: true
 * @param {React.ReactNode} fallback - Optional fallback UI to render if permission is not granted
 * @param {React.ReactNode} children - Content to render if permission is granted
 */
export default function PermissionGate({ permission, requireAll = true, fallback = null, children }) {
    // Get auth props - this ensures component re-renders when auth props change
    const { auth } = usePage().props
    // Call usePermission hook - it will recalculate when auth props change
    const { hasPermission } = usePermission(permission, requireAll)
    
    // The hook will automatically recalculate when auth.tenant_role or auth.role_permissions change
    // because usePage() is reactive to prop changes in Inertia
    
    if (!hasPermission) {
        return fallback
    }
    
    return children
}
