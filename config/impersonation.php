<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Session TTL (minutes)
    |--------------------------------------------------------------------------
    */
    'ttl_minutes' => (int) env('IMPERSONATION_TTL_MINUTES', 30),

    /*
    |--------------------------------------------------------------------------
    | Policy / Gate abilities treated as read-only safe
    |--------------------------------------------------------------------------
    |
    | While impersonating in read_only (or assisted) mode, any other policy
    | ability is denied before the policy runs.
    |
    */
    'read_only_abilities' => [
        'view',
        'viewAny',
        'viewTrash',
        'viewAnyForStaff',
        'viewForStaff',
        'viewEngineeringTickets',
        'viewSLA',
        'viewAuditLog',
    ],

    /*
    |--------------------------------------------------------------------------
    | Extra gate names allowed during read-only impersonation
    |--------------------------------------------------------------------------
    */
    'read_only_extra_abilities' => [
        'brand-intelligence.view-decision-trace',
    ],
];
