<?php

namespace App\Services;

use App\Services\Notifications\Contracts\NotificationChannel;
use Illuminate\Support\Facades\Log;

/**
 * Config-driven fan-out to notification channels (in_app, email, push).
 *
 * Future extension (see config/notifications.php):
 * - Merge user preferences before sending.
 * - Tenant-level channel overrides.
 */
final class NotificationOrchestrator
{
    /**
     * @param  array<string, NotificationChannel>  $channels
     */
    public function __construct(
        protected array $channels
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(string $event, array $payload): void
    {
        if (! filter_var(config('notifications.enabled', false), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $definition = config("notifications.events.{$event}");
        if (! is_array($definition)) {
            Log::debug('[NotificationOrchestrator] Unknown event', ['event' => $event]);

            return;
        }

        $channelNames = $definition['channels'] ?? [];
        if (! is_array($channelNames) || $channelNames === []) {
            return;
        }

        foreach ($channelNames as $name) {
            $name = (string) $name;
            $handler = $this->channels[$name] ?? null;
            if (! $handler instanceof NotificationChannel) {
                Log::warning('[NotificationOrchestrator] Missing channel handler', ['channel' => $name, 'event' => $event]);

                continue;
            }

            try {
                $handler->send($event, $payload);
            } catch (\Throwable $e) {
                Log::error('[NotificationOrchestrator] Channel failed', [
                    'channel' => $name,
                    'event' => $event,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
