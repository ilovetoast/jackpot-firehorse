import { usePermission } from '../hooks/usePermission'

/**
 * Component that conditionally renders children based on permission check
 * @param {string|string[]} permission - Permission name(s) to check
 * @param {boolean} requireAll - If true, all permissions must be present (AND). If false, any permission is sufficient (OR). Default: true
 * @param {React.ReactNode} fallback - Optional fallback UI to render if permission is not granted
 * @param {React.ReactNode} children - Content to render if permission is granted
 */
export default function PermissionGate({ permission, requireAll = true, fallback = null, children }) {
    const { can } = usePermission()
    const perms = Array.isArray(permission) ? permission : [permission]
    const hasPermission = requireAll
        ? perms.every((p) => can(p))
        : perms.some((p) => can(p))

    if (!hasPermission) {
        return fallback
    }

    return children
}
