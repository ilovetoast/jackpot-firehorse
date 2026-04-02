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
         */
        'auto_ticket_enabled' => env('RELIABILITY_ASSET_INCIDENT_AUTO_TICKET', true),

        /*
         * Minimum successful auto-recover passes that failed to fix the incident
         * before an internal ticket is allowed. Each system:auto-recover run
         * increments repair_attempts after a failed repair.
         *
         * Default 3: gives reconciliation/retries time before one ticket per asset.
         */
        'min_repair_attempts_before_ticket' => (int) env('RELIABILITY_ASSET_INCIDENT_MIN_REPAIR_ATTEMPTS', 3),
    ],

];
