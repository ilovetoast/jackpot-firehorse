<?php

return [
    /*
    |--------------------------------------------------------------------------
    | SLA Plans
    |--------------------------------------------------------------------------
    |
    | Define SLA plans that map to subscription plans.
    | These are internal operational targets, not customer-facing guarantees.
    |
    | Each plan includes:
    | - first_response_target_minutes: Target time for first response (required)
    | - resolution_target_minutes: Target time for resolution (nullable)
    | - support_hours: Business hours definition (JSON structure)
    | - escalation_rules: Escalation configuration (structure only, no notifications yet)
    |
    | Support hours format:
    | - days: Array of day numbers (1=Monday, 7=Sunday)
    | - start: Start time in HH:MM format
    | - end: End time in HH:MM format
    | - timezone: Timezone identifier (e.g., 'America/New_York', 'UTC')
    |
    */

    'free' => [
        'first_response_target_minutes' => 1440, // 24 hours
        'resolution_target_minutes' => null, // No resolution target for free plan
        'support_hours' => [
            'days' => [1, 2, 3, 4, 5], // Monday-Friday
            'start' => '09:00',
            'end' => '17:00',
            'timezone' => 'UTC',
        ],
        'escalation_rules' => [
            // Structure for future notification integration
            // 'approaching_breach_threshold' => 0.8, // 80% of time elapsed
        ],
    ],

    'starter' => [
        'first_response_target_minutes' => 480, // 8 hours
        'resolution_target_minutes' => 2880, // 48 hours
        'support_hours' => [
            'days' => [1, 2, 3, 4, 5], // Monday-Friday
            'start' => '09:00',
            'end' => '17:00',
            'timezone' => 'UTC',
        ],
        'escalation_rules' => [
            // Structure for future notification integration
        ],
    ],

    'pro' => [
        'first_response_target_minutes' => 240, // 4 hours
        'resolution_target_minutes' => 1440, // 24 hours
        'support_hours' => [
            'days' => [1, 2, 3, 4, 5], // Monday-Friday
            'start' => '09:00',
            'end' => '17:00',
            'timezone' => 'UTC',
        ],
        'escalation_rules' => [
            // Structure for future notification integration
        ],
    ],

    'enterprise' => [
        'first_response_target_minutes' => 120, // 2 hours
        'resolution_target_minutes' => 480, // 8 hours
        'support_hours' => [
            'days' => [1, 2, 3, 4, 5, 6], // Monday-Saturday
            'start' => '08:00',
            'end' => '20:00',
            'timezone' => 'UTC',
        ],
        'escalation_rules' => [
            // Structure for future notification integration
        ],
    ],
];
