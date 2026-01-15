<?php

/**
 * 🔒 STABILIZATION A2 — Alerts & Tickets Operational Safety Configuration
 * 
 * Operational safety caps to prevent system overload during failure scenarios.
 * These caps are non-blocking - they log warnings and skip creation when exceeded.
 * 
 * Purpose:
 * - Prevent alert/ticket storms during systemic failures
 * - Maintain system stability during high-failure scenarios
 * - Allow graceful degradation without blocking core processing
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Alert Rate Cap
    |--------------------------------------------------------------------------
    |
    | Maximum number of new AlertCandidates that can be created per tenant
    | per hour. When exceeded, new alert creation is skipped with a warning log.
    |
    | Default: 100 alerts per tenant per hour
    | Set to 0 to disable rate capping.
    |
    */

    'max_per_tenant_per_hour' => env('ALERTS_MAX_PER_TENANT_PER_HOUR', 100),

    /*
    |--------------------------------------------------------------------------
    | Ticket Auto-Creation Rate Cap
    |--------------------------------------------------------------------------
    |
    | Maximum number of SupportTickets that can be automatically created
    | per hour (across all tenants). When exceeded, ticket creation is skipped
    | with a warning log, but alerts remain open.
    |
    | Default: 50 auto-created tickets per hour
    | Set to 0 to disable rate capping.
    |
    */

    'tickets' => [
        'max_auto_create_per_hour' => env('TICKETS_MAX_AUTO_CREATE_PER_HOUR', 50),
    ],

];
