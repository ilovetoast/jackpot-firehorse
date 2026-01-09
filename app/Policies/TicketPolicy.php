<?php

namespace App\Policies;

use App\Enums\TicketType;
use App\Models\Ticket;
use App\Models\User;

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
 * Staff Users (Site Support, Site Admin, Site Owner, Site Engineering, Compliance):
 * - viewAnyForStaff: Requires 'tickets.view_staff' permission OR has site role (backward compatibility)
 * - viewForStaff: Requires 'tickets.view_staff' permission OR has site role
 * - assign: Requires 'tickets.assign' permission OR has site_support/site_admin/site_owner role
 * - addInternalNote: Requires 'tickets.add_internal_note' permission OR has site_support/site_admin/site_owner/site_engineering role
 * - convert: Requires 'tickets.convert' permission OR has site_admin/site_owner role
 * - viewSLA: Requires 'tickets.view_sla' permission OR has site role (never tenants)
 * - viewAuditLog: Requires 'tickets.view_audit_log' permission OR has site_owner/compliance role
 * - createEngineeringTicket: Requires 'tickets.create_engineering' permission OR has site_engineering/site_admin/site_owner role
 * - viewEngineeringTickets: Requires 'tickets.view_engineering' permission OR has site_engineering/site_admin/site_owner/compliance role
 * - linkDiagnostic: Requires 'tickets.link_diagnostic' permission OR has site_engineering/site_admin/site_owner role
 *
 * Default Permissions (assigned in PermissionSeeder):
 * - Site Owner: All ticket permissions
 * - Site Admin: Full ticket access (except view_audit_log)
 * - Site Support: Can manage tenant tickets and add internal notes
 * - Site Engineering: Can view and manage internal tickets
 * - Compliance: View-only access (view_staff, view_engineering, view_audit_log)
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
     * Requires 'tickets.view_staff' permission OR has a site role (backward compatibility).
     */
    public function viewAnyForStaff(User $user): bool
    {
        // Check permission first
        if ($user->can('tickets.view_staff')) {
            return true;
        }
        
        // Backward compatibility: check for site roles
        return $user->hasAnyRole([
            'site_support',
            'site_admin',
            'site_owner',
            'compliance',
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
     * Determine if the user can assign or reassign tickets.
     * Requires 'tickets.assign' permission OR has site_support/site_admin/site_owner role (backward compatibility).
     */
    public function assign(User $user, Ticket $ticket): bool
    {
        // Check permission first
        if ($user->can('tickets.assign')) {
            return true;
        }
        
        // Backward compatibility: check for site roles
        return $user->hasAnyRole([
            'site_support',
            'site_admin',
            'site_owner',
        ]);
    }

    /**
     * Determine if the user can add internal notes to tickets.
     * Requires 'tickets.add_internal_note' permission OR has site_support/site_admin/site_owner/site_engineering role (backward compatibility).
     */
    public function addInternalNote(User $user, Ticket $ticket): bool
    {
        // Check permission first
        if ($user->can('tickets.add_internal_note')) {
            return true;
        }
        
        // Backward compatibility: check for site roles
        return $user->hasAnyRole([
            'site_support',
            'site_admin',
            'site_owner',
            'site_engineering',
        ]);
    }

    /**
     * Determine if the user can create internal engineering tickets.
     * Requires 'tickets.create_engineering' permission OR has site_engineering/site_admin/site_owner role (backward compatibility).
     */
    public function createEngineeringTicket(User $user): bool
    {
        // Check permission first
        if ($user->can('tickets.create_engineering')) {
            return true;
        }
        
        // Backward compatibility: check for site roles
        return $user->hasAnyRole([
            'site_engineering',
            'site_admin',
            'site_owner',
        ]);
    }

    /**
     * Determine if the user can view engineering tickets.
     * Requires 'tickets.view_engineering' permission OR has site_engineering/site_admin/site_owner/compliance role (backward compatibility).
     */
    public function viewEngineeringTickets(User $user): bool
    {
        // Check permission first
        if ($user->can('tickets.view_engineering')) {
            return true;
        }
        
        // Backward compatibility: check for site roles
        return $user->hasAnyRole([
            'site_engineering',
            'site_admin',
            'site_owner',
            'compliance',
        ]);
    }

    /**
     * Determine if the user can link diagnostic items to tickets.
     * Requires 'tickets.link_diagnostic' permission OR has site_engineering/site_admin/site_owner role (backward compatibility).
     */
    public function linkDiagnostic(User $user, Ticket $ticket): bool
    {
        // Check permission first
        if ($user->can('tickets.link_diagnostic')) {
            return true;
        }
        
        // Backward compatibility: check for site roles
        return $user->hasAnyRole([
            'site_engineering',
            'site_admin',
            'site_owner',
        ]);
    }

    /**
     * Determine if the user can convert a tenant ticket to an internal ticket.
     * Requires 'tickets.convert' permission OR has site_admin/site_owner role (backward compatibility).
     */
    public function convert(User $user, Ticket $ticket): bool
    {
        // Check permission first
        if ($user->can('tickets.convert')) {
            return true;
        }
        
        // Backward compatibility: check for site roles
        return $user->hasAnyRole([
            'site_admin',
            'site_owner',
        ]);
    }

    /**
     * Determine if the user can view SLA data for tickets.
     * Requires 'tickets.view_sla' permission OR has site role (backward compatibility).
     * Never allowed for tenant users.
     */
    public function viewSLA(User $user, Ticket $ticket): bool
    {
        // Check permission first
        if ($user->can('tickets.view_sla')) {
            return true;
        }
        
        // Backward compatibility: check for site roles
        return $this->viewAnyForStaff($user);
    }

    /**
     * Determine if the user can view the audit log for tickets.
     * Requires 'tickets.view_audit_log' permission OR has site_owner/compliance role (backward compatibility).
     */
    public function viewAuditLog(User $user, Ticket $ticket): bool
    {
        // Check permission first
        if ($user->can('tickets.view_audit_log')) {
            return true;
        }
        
        // Backward compatibility: check for site roles
        return $user->hasAnyRole([
            'site_owner',
            'compliance',
        ]);
    }
}
