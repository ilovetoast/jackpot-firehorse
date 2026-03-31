import { switchCompanyWorkspace } from './workspaceCompanySwitch'

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
    switchCompanyWorkspace({ companyId: clientTenantId, brandId, redirect })
}
