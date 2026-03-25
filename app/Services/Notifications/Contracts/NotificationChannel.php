<?php

namespace App\Services\Notifications\Contracts;

interface NotificationChannel
{
    /**
     * Deliver a configured notification event to this channel.
     *
     * @param  array<string, mixed>  $payload  Event-specific (e.g. user_ids, title, message, brand_id).
     */
    public function send(string $event, array $payload): void;
}
