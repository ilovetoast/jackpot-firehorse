import { usePermission } from './usePermission'

/**
 * Admin Command Center permission helpers.
 *
 * Maps permission names to section visibility.
 * Use for role-aware tiles and sections.
 */
export function useAdminPermissions() {
    const { can } = usePermission()

    return {
        can,
        // Operations & Recovery
        canViewOperations: can('tickets.view_staff') || can('tickets.view_engineering'),
        // Support & SLA
        canViewSupport: can('tickets.view_staff') || can('tickets.view_any'),
        canViewSLA: can('tickets.view_sla'),
        canViewEngineering: can('tickets.view_engineering'),
        // AI & Automation
        canViewAI: can('ai.dashboard.view'),
        canManageAIBudgets: can('ai.budgets.view') || can('ai.budgets.manage'),
        // Billing
        canViewBilling: true, // Site admins always see billing
        // Organization
        canManageOrganization: can('company.manage'),
        // System Configuration
        canManagePermissions: can('permissions.manage'),
        canViewMetadataRegistry: can('metadata.registry.view'),
    }
}
