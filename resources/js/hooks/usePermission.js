import { usePage } from '@inertiajs/react'

export function usePermission() {
    const { auth } = usePage().props

    const can = (permission) => {
        return auth.effective_permissions?.includes(permission)
    }

    return { can }
}
