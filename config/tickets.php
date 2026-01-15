<?php

/**
 * 🔒 Phase 5A Step 3 — External Ticket System Configuration
 * 
 * Configuration for external ticket system adapter integration.
 * 
 * Driver Options:
 * - 'null': Null adapter (stub, no external API calls) - DEFAULT
 * - 'zendesk': Zendesk adapter (future implementation)
 * - 'jira': Jira adapter (future implementation)
 * - 'linear': Linear adapter (future implementation)
 * 
 * Usage:
 * - Set TICKETS_DRIVER environment variable to configure adapter
 * - Defaults to 'null' if not configured
 * - Null adapter logs intent but does not make API calls
 */

return [

    /*
    |--------------------------------------------------------------------------
    | External Ticket System Driver
    |--------------------------------------------------------------------------
    |
    | This option controls which external ticket system adapter is used.
    | 
    | Supported drivers: "null", "zendesk", "jira", "linear"
    | Default: "null" (stub adapter, no external API calls)
    |
    */

    'driver' => env('TICKETS_DRIVER', 'null'),

    /*
    |--------------------------------------------------------------------------
    | Driver-specific Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration options specific to each driver.
    | These will be used by adapter implementations.
    |
    */

    'zendesk' => [
        'subdomain' => env('ZENDESK_SUBDOMAIN'),
        'api_token' => env('ZENDESK_API_TOKEN'),
        'user_email' => env('ZENDESK_USER_EMAIL'),
    ],

    'jira' => [
        'url' => env('JIRA_URL'),
        'username' => env('JIRA_USERNAME'),
        'api_token' => env('JIRA_API_TOKEN'),
    ],

    'linear' => [
        'api_key' => env('LINEAR_API_KEY'),
        'team_id' => env('LINEAR_TEAM_ID'),
    ],

];
