/**
 * Staged intake + References (reference materials) in the asset library sidebar.
 * Visible to tenant owner/admin/agency_admin, brand admin/brand_manager, or anyone
 * who belongs to an agency tenant (agency staff in client workspaces).
 */
export function canViewAssetSystemFolders(auth) {
    if (!auth?.user) return false
    const tr = String(auth.tenant_role || '').toLowerCase()
    if (['owner', 'admin', 'agency_admin'].includes(tr)) return true
    const br = String(auth.brand_role || '').toLowerCase()
    if (['admin', 'brand_manager'].includes(br)) return true
    const companies = Array.isArray(auth.companies) ? auth.companies : []
    return companies.some((c) => c?.is_agency === true)
}
