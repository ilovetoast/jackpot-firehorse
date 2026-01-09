<?php

/**
 * Automation Configuration
 *
 * Centralized configuration for AI-powered automation triggers.
 * Controls which automations are enabled, their thresholds, and execution model.
 *
 * All automations respect tenant boundaries and are auditable via AI agent runs.
 * No automation ever mutates tenant-facing data without explicit human approval.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Automation Enabled
    |--------------------------------------------------------------------------
    |
    | Master switch for all automation features.
    | When disabled, no automation triggers will fire.
    |
    */
    'enabled' => env('AUTOMATION_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Automation Triggers
    |--------------------------------------------------------------------------
    |
    | Configuration for each automation trigger type.
    |
    | Each trigger can be:
    | - enabled: Master switch for this specific trigger
    | - async: Whether to run synchronously (inline) or asynchronously (queued)
    | - Additional trigger-specific settings (thresholds, intervals, etc.)
    |
    | Execution Model:
    | - Synchronous (async=false): Critical triggers that affect immediate UX
    | - Asynchronous (async=true): Non-critical triggers that can be delayed
    |
    */
    'triggers' => [
        'ticket_summarization' => [
            'enabled' => env('AUTOMATION_TICKET_SUMMARY_ENABLED', true),
            'message_threshold' => env('AUTOMATION_MESSAGE_THRESHOLD', 5),
            'async' => true,
        ],

        'ticket_classification' => [
            'enabled' => env('AUTOMATION_CLASSIFICATION_ENABLED', true),
            'on_creation' => true,
            'on_escalation' => true,
            'async' => false, // Critical, run inline for immediate UX
        ],

        'sla_risk_detection' => [
            'enabled' => env('AUTOMATION_SLA_RISK_ENABLED', true),
            'scan_interval' => 'hourly',
            'async' => true,
        ],

        'error_pattern_detection' => [
            'enabled' => env('AUTOMATION_ERROR_PATTERNS_ENABLED', true),
            'time_window_minutes' => env('AUTOMATION_ERROR_WINDOW_MINUTES', 60),
            'error_threshold' => env('AUTOMATION_ERROR_THRESHOLD', 5),
            'scan_interval' => 'hourly',
            'async' => true,
        ],

        'duplicate_detection' => [
            'enabled' => env('AUTOMATION_DUPLICATE_ENABLED', true),
            'async' => false, // Critical, run inline
        ],
    ],
];
