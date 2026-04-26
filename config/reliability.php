<?php

/**
 * Reliability engine: incidents, auto-recover, ticket escalation.
 *
 * Asset/job pipeline incidents often affect many assets at once. Without tighter
 * rules, each critical incident can open an internal support ticket immediately
 * and trigger ticket AI agents — high noise and cost.
 */
return [

    'asset_incident' => [
        /*
         * When false, asset- or job-backed incidents never auto-create internal
         * support tickets (ops can still create from the incident UI).
         * Change here if needed; not driven by .env to avoid config sprawl.
         */
        'auto_ticket_enabled' => true,

        /*
         * Minimum auto-recover passes that failed to fix the incident before an
         * internal ticket is allowed. Each system:auto-recover run increments
         * repair_attempts after a failed repair. Default 3: retries before one ticket.
         */
        'min_repair_attempts_before_ticket' => 3,
    ],

    /*
     * `system:auto-recover` runs on a schedule; each pass against an unresolved
     * incident was incrementing repair_attempts with no cap — unbounded growth in
     * Operations Center. After this many *failed* recovery passes, further auto
     * repair, reconciliation, and counter increments stop until manual resolve.
     * Escalation (ticket) still runs once in the quiesce path if policy allows.
     */
    'max_auto_repair_attempts' => 30,

];
