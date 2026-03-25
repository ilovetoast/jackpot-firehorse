<?php

namespace App\Services;

use App\Models\User;
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

            $channelPayload = $payload;
            if ($name === 'push') {
                $channelPayload = $this->filterPushPayloadByUserPreferences($event, $payload);
                if (($channelPayload['user_ids'] ?? []) === []) {
                    continue;
                }
            }

            try {
                $handler->send($event, $channelPayload);
            } catch (\Throwable $e) {
                Log::error('[NotificationOrchestrator] Channel failed', [
                    'channel' => $name,
                    'event' => $event,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Drops user ids that opted out of push for this event's preference group (activity / account / system).
     *
     * Future: email channel preferences; tenant defaults merged before this filter.
     */
    protected function filterPushPayloadByUserPreferences(string $event, array $payload): array
    {
        $definition = config("notifications.events.{$event}");
        $category = is_array($definition) ? ($definition['category'] ?? 'activity') : 'activity';
        $category = is_string($category) && $category !== '' ? $category : 'activity';

        $ids = $payload['user_ids'] ?? [];
        if (! is_array($ids) || $ids === []) {
            return $payload;
        }

        $intIds = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $id > 0)));
        if ($intIds === []) {
            $payload['user_ids'] = [];

            return $payload;
        }

        $users = User::query()->whereIn('id', $intIds)->get(['id', 'notification_preferences', 'push_enabled']);
        $allowed = [];
        foreach ($users as $user) {
            if (! $user->push_enabled) {
                continue;
            }
            if (data_get($user->getNotificationPreferences(), "{$category}.push", true)) {
                $allowed[] = $user->id;
            }
        }

        $payload['user_ids'] = $allowed;

        return $payload;
    }
}
