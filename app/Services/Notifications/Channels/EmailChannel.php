<?php

namespace App\Services\Notifications\Channels;

use App\Services\Notifications\Contracts\NotificationChannel;

/**
 * Reserved for orchestrated transactional email (Mailables) per event.
 * Intentionally empty until templates and triggers are wired.
 */
final class EmailChannel implements NotificationChannel
{
    public function send(string $event, array $payload): void
    {
        // Future: map $event to Mailable classes; respect user email preferences.
    }
}
