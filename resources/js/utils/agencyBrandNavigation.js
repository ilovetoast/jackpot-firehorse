import { router } from '@inertiajs/react'
import { showWorkspaceSwitchingOverlay } from './workspaceSwitchOverlay'

/**
 * Switch into a client company + brand, then redirect to an /app path (CompanyController::switch).
 *
 * @param {number} clientTenantId
 * @param {number} brandId
 * @param {string} path — e.g. /app/brands/1/brand-guidelines/builder
 */
export function agencyNavigateToBrandPath(clientTenantId, brandId, path) {
    if (!clientTenantId || !brandId || !path) {
        return
    }
    const redirect = path.startsWith('/app') ? path : `/app${path.startsWith('/') ? '' : '/'}${path}`
    showWorkspaceSwitchingOverlay('company')
    router.post(
        `/app/companies/${clientTenantId}/switch`,
        { brand_id: brandId, redirect },
        {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                window.location.href = redirect
            },
            onError: () => {
                window.location.href = redirect
            },
        }
    )
}
