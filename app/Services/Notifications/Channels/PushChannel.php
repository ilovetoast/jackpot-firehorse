<?php

namespace App\Services\Notifications\Channels;

use App\Services\Notifications\Contracts\NotificationChannel;
use Illuminate\Support\Facades\Log;

/**
 * OneSignal REST push — credentials wired; delivery currently stubbed (log only).
 * All HTTP stays in this class when enabled.
 */
final class PushChannel implements NotificationChannel
{
    public function send(string $event, array $payload): void
    {
        if (! filter_var(config('notifications.enabled', false), FILTER_VALIDATE_BOOLEAN)) {
            Log::info('Push skipped (notifications orchestration disabled)');

            return;
        }

        if (! filter_var(env('PUSH_NOTIFICATIONS_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
            Log::info('Push skipped (PUSH_NOTIFICATIONS_ENABLED disabled)');

            return;
        }

        $userIds = $payload['user_ids'] ?? [];
        if (! is_array($userIds) || $userIds === []) {
            return;
        }

        $externalIds = [];
        foreach ($userIds as $id) {
            $s = (string) $id;
            $externalIds[] = str_starts_with($s, 'user_') ? $s : 'user_'.$s;
        }
        $externalIds = array_values(array_unique(array_filter($externalIds, fn ($v) => $v !== '')));

        Log::info('Push notification stub', [
            'event' => $event,
            'external_ids' => $externalIds,
            'title' => $payload['title'] ?? null,
            'message' => $payload['message'] ?? null,
            'note' => 'OneSignal request not sent — stub mode until go-live.',
        ]);
    }
}
