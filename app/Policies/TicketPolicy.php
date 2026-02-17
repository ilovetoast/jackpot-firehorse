<?php

namespace App\Policies;

use App\Enums\TicketType;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AuthPermissionService;

/**
 * Ticket Policy
 *
 * Authorization rules for ticket access (both tenant and staff).
 * 
 * This policy uses permissions (e.g., 'tickets.view_staff', 'tickets.assign') for authorization.
 * Permissions can be managed via the Admin > Permissions UI.
 * 
 * Permission Matrix:
 * 
 * Tenant Users:
 * - viewAny: Must belong to at least one tenant (uses 'tickets.view_tenant' permission if assigned)
 * - view: Only type=tenant tickets for their tenant
 * - create: Must belong to at least one tenant (uses 'tickets.create' permission if assigned)
 * - reply: Same as view (uses 'tickets.reply' permission if assigned)
 * - Cannot see: tenant_internal, internal tickets, internal notes, SLA data
 *
 * Staff Users (Site Support, Site Admin, Site Owner, Site Engineering, Site Compliance):
 * - viewAnyForStaff: Requires 'tickets.view_staff' permission OR has site role (backward compatibility)
 * - viewForStaff: Requires 'tickets.view_staff' permission OR has site role
 * - assign: Requires 'tickets.assign' permission OR has site_support/site_admin/site_owner role
 * - addInternalNote: Requires 'tickets.add_internal_note' permission OR has site_support/site_admin/site_owner/site_engineering role
 * - convert: Requires 'tickets.convert' permission OR has site_admin/site_owner role
 * - viewSLA: Requires 'tickets.view_sla' permission OR has site role (never tenants)
 * - viewAuditLog: Requires 'tickets.view_audit_log' permission OR has site_owner/site_compliance role
 * - createEngineeringTicket: Requires 'tickets.create_engineering' permission OR has site_engineering/site_admin/site_owner role
 * - viewEngineeringTickets: Requires 'tickets.view_engineering' permission OR has site_engineering/site_admin/site_owner/site_compliance role
 * - linkDiagnostic: Requires 'tickets.link_diagnostic' permission OR has site_engineering/site_admin/site_owner role
 *
 * Default Permissions (assigned in PermissionSeeder):
 * - Site Owner: All ticket permissions
 * - Site Admin: Full ticket access (except view_audit_log)
 * - Site Support: Can manage tenant tickets and add internal notes
 * - Site Engineering: Can view and manage internal tickets
 * - Site Compliance: View-only access (view_staff, view_engineering, view_audit_log)
 *
 * These rules ensure strict tenant isolation while providing staff with operational capabilities.
 * Permissions can be customized via Admin > Permissions UI.
 */
class TicketPolicy
{
    /**
     * Determine if the user can view any tickets.
     */
    public function viewAny(User $user): bool
    {
        // User must belong to at least one tenant
        return $user->tenants()->exists();
    }

    /**
     * Determine if the user can view the ticket.
     * Only tenant tickets belonging to user's tenant are visible.
     */
    public function view(User $user, Ticket $ticket): bool
    {
        // Ticket must be of type 'tenant' (not tenant_internal or internal)
        if ($ticket->type !== TicketType::TENANT) {
            return false;
        }

        // Ticket must belong to a tenant
        if (!$ticket->tenant_id) {
            return false;
        }

        // User must belong to the ticket's tenant
        return $user->tenants()->where('tenants.id', $ticket->tenant_id)->exists();
    }

    /**
     * Determine if the user can create tickets.
     */
    public function create(User $user): bool
    {
        // User must belong to at least one tenant
        return $user->tenants()->exists();
    }

    /**
     * Determine if the user can reply to the ticket.
     * Same visibility rules as view.
     */
    public function reply(User $user, Ticket $ticket): bool
    {
        return $this->view($user, $ticket);
    }

    /**
     * Determine if the user can view any tickets in the staff/admin interface.
     * Requires 'tickets.view_staff' permission (site roles only; admin has no tenant).
     */
    public function viewAnyForStaff(User $user): bool
    {
        // Use AuthPermissionService for consistency with site permission hierarchy
        // Admin pages have no tenant — pass null so we get only Spatie (site) role permissions
        if (app(AuthPermissionService::class)->can($user, 'tickets.view_staff', null, null)) {
            return true;
        }

        // Fallback: has site role (backward compatibility)
        return $user->hasAnyRole([
            'site_support',
            'site_admin',
            'site_owner',
            'site_compliance',
            'site_engineering',
        ]);
    }

    /**
     * Determine if the user can view the ticket in the staff/admin interface.
     * Staff can see all ticket types (tenant, tenant_internal, internal).
     */
    public function viewForStaff(User $user, Ticket $ticket): bool
    {
        // Must have a site role
        if (!$this->viewAnyForStaff($user)) {
            return false;
        }

        // Staff can see all ticket types
        return true;
    }

    /**
     * Check site permission via AuthPermissionService (admin context: no tenant).
     */
    private function hasSitePermission(User $user, string $permission): bool
    {
        return app(AuthPermissionService::class)->can($user, $permission, null, null);
    }

    /**
     * Determine if the user can assign or reassign tickets.
     */
    public function assign(User $user, Ticket $ticket): bool
    {
        return $this->hasSitePermission($user, 'tickets.assign')
            || $user->hasAnyRole(['site_support', 'site_admin', 'site_owner']);
    }

    /**
     * Determine if the user can add internal notes to tickets.
     */
    public function addInternalNote(User $user, Ticket $ticket): bool
    {
        return $this->hasSitePermission($user, 'tickets.add_internal_note')
            || $user->hasAnyRole(['site_support', 'site_admin', 'site_owner', 'site_engineering']);
    }

    /**
     * Determine if the user can create internal engineering tickets.
     */
    public function createEngineeringTicket(User $user): bool
    {
        return $this->hasSitePermission($user, 'tickets.create_engineering')
            || $user->hasAnyRole(['site_engineering', 'site_admin', 'site_owner']);
    }

    /**
     * Determine if the user can view engineering tickets.
     */
    public function viewEngineeringTickets(User $user): bool
    {
        return $this->hasSitePermission($user, 'tickets.view_engineering')
            || $user->hasAnyRole(['site_engineering', 'site_admin', 'site_owner', 'site_compliance']);
    }

    /**
     * Determine if the user can link diagnostic items to tickets.
     */
    public function linkDiagnostic(User $user, Ticket $ticket): bool
    {
        return $this->hasSitePermission($user, 'tickets.link_diagnostic')
            || $user->hasAnyRole(['site_engineering', 'site_admin', 'site_owner']);
    }

    /**
     * Determine if the user can convert a tenant ticket to an internal ticket.
     */
    public function convert(User $user, Ticket $ticket): bool
    {
        return $this->hasSitePermission($user, 'tickets.convert')
            || $user->hasAnyRole(['site_admin', 'site_owner']);
    }

    /**
     * Determine if the user can view SLA data for tickets.
     */
    public function viewSLA(User $user, Ticket $ticket): bool
    {
        return $this->hasSitePermission($user, 'tickets.view_sla')
            || $this->viewAnyForStaff($user);
    }

    /**
     * Determine if the user can view the audit log for tickets.
     */
    public function viewAuditLog(User $user, Ticket $ticket): bool
    {
        return $this->hasSitePermission($user, 'tickets.view_audit_log')
            || $user->hasAnyRole(['site_owner', 'site_compliance']);
    }
}
