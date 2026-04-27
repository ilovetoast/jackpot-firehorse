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

    /*
    |--------------------------------------------------------------------------
    | Support Round-Robin Default Bucket
    |--------------------------------------------------------------------------
    |
    | Default user IDs for the support round-robin when the bucket is empty.
    | Used by default in Cedar (and other environments) until the bucket
    | is populated via the Support Tickets page.
    |
    */

    'round_robin_default_user_ids' => [1],

    /*
    |--------------------------------------------------------------------------
    | Engineering (internal) round-robin default bucket
    |--------------------------------------------------------------------------
    |
    | When engineering_round_robin_users is empty, these user IDs are used for
    | assignment. Configure via comma-separated env ENGINEERING_ROUND_ROBIN_DEFAULT_USER_IDS
    | or fall back to [1] (same idea as support).
    |
    */

    'engineering_round_robin_default_user_ids' => (static function () {
        $ids = array_values(array_filter(array_map(
            'intval',
            explode(',', (string) env('ENGINEERING_ROUND_ROBIN_DEFAULT_USER_IDS', '1'))
        )));

        return $ids === [] ? [1] : $ids;
    })(),

    /*
    |--------------------------------------------------------------------------
    | Activity log tenant for internal tickets
    |--------------------------------------------------------------------------
    |
    | activity_events.tenant_id is required (FK to tenants). Internal / engineering
    | tickets may have null tenant_id; bulk resolve and other staff actions still
    | log activity. If unset, the lowest existing tenant id is used. Optionally set
    | TICKETS_ACTIVITY_FALLBACK_TENANT_ID to a stable tenant (e.g. site owner).
    |
    */

    'activity_fallback_tenant_id' => env('TICKETS_ACTIVITY_FALLBACK_TENANT_ID'),

];
