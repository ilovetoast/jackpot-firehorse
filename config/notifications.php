<?php

/**
 * Notification orchestration (in-app, email, push).
 *
 * This does not replace {@see \App\Services\NotificationGroupService} or Mailables;
 * channels call those systems where appropriate.
 *
 * User prefs: {@see \App\Models\User::getNotificationPreferences()} (groups: activity, account, system).
 * Event → group: `category` on each event below. Orchestrator filters push recipients before {@see \App\Services\Notifications\Channels\PushChannel}.
 *
 * Future: email toggles per group; per-event overrides; tenant/plan defaults; frequency / digest controls.
 */
return [

    'enabled' => env('NOTIFICATIONS_ENABLED', false),

    'events' => [

        'generative.published' => [
            'channels' => ['in_app', 'push'],
            'category' => 'activity',
        ],

        // Reserved for when orchestrated (stubs / future wiring):
        // 'asset.approved' => ['channels' => ['in_app', 'push'], 'category' => 'activity'],
        // 'invite.sent' => ['channels' => ['email', 'push'], 'category' => 'account'],

    ],

];
