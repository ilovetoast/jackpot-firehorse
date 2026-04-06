/**
 * Normalizes GET /app/api/brands/{brand}/prostaff/dashboard JSON (object or legacy array).
 *
 * @param {unknown} data
 * @returns {{ active: Array<Record<string, unknown>>, pendingInvitations: Array<Record<string, unknown>> }}
 */
export function parseProstaffDashboardResponse(data) {
    if (data && typeof data === 'object' && !Array.isArray(data)) {
        const active = Array.isArray(data.active) ? data.active : []
        const pendingInvitations = Array.isArray(data.pending_invitations) ? data.pending_invitations : []
        return { active, pendingInvitations }
    }
    if (Array.isArray(data)) {
        return { active: data, pendingInvitations: [] }
    }
    return { active: [], pendingInvitations: [] }
}
