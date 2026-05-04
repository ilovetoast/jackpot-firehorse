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
         * repair_attempts after a failed repair. Higher = fewer tickets during outages.
         */
        'min_repair_attempts_before_ticket' => 5,
    ],

    /*
     * `system:auto-recover` runs on a schedule; each pass against an unresolved
     * incident was incrementing repair_attempts with no cap — unbounded growth in
     * Operations Center. After this many *failed* recovery passes, further auto
     * repair, reconciliation, and counter increments stop until manual resolve.
     * Escalation (ticket) still runs once in the quiesce path if policy allows.
     */
    'max_auto_repair_attempts' => 30,

    /*
     * Incidents stuck longer than this get severity bumped (see EscalationPolicy).
     * Larger value reduces premature escalation during slow queues.
     */
    'age_escalation_minutes' => 30,

    /*
     * assets:watchdog — longer waits reduce duplicate incidents when the pipeline
     * is merely slow, and cut SupportTicket noise from the same stuck assets.
     */
    'watchdog' => [
        'stuck_analysis_grace_minutes' => 22,
        'failed_thumbnail_cooldown_minutes' => 12,
        'processing_stale_minutes' => 28,
        'auto_support_ticket_enabled' => true,
        /** Only open a SupportTicket after the asset has been this stale (updated_at). */
        'support_ticket_min_stale_minutes' => 40,
        /**
         * When true, uploading incidents dispatch SupportIncidentClassificationAgentJob.
         * False reduces agent load; auto-recover + escalation still apply.
         */
        'uploading_requires_support_agent' => false,
    ],

    /** UploadSession / derivative ticket creation: minimum failures before internal ticket. */
    'upload_escalation_min_failures' => 6,
    'derivative_escalation_min_failures' => 6,

];
