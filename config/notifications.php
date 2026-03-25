<?php

/**
 * Notification orchestration (in-app, email, push).
 *
 * This does not replace {@see \App\Services\NotificationGroupService} or Mailables;
 * channels call those systems where appropriate.
 *
 * Future extension (not implemented here):
 * - User-level preferences (per channel, per event) stored on users or a dedicated table.
 * - Tenant/plan overrides (e.g. disable push for certain workspaces) via admin UI or plan features.
 * - Per-tenant defaults merged with user preferences before dispatch.
 */
return [

    'enabled' => env('NOTIFICATIONS_ENABLED', false),

    'events' => [

        'generative.published' => [
            'channels' => ['in_app', 'push'],
        ],

    ],

];
