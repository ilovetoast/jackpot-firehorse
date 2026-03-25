<?php

namespace App\Services\Notifications\Channels;

use App\Services\Notifications\Contracts\NotificationChannel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OneSignal REST push. All HTTP and credentials stay here.
 */
final class PushChannel implements NotificationChannel
{
    public function send(string $event, array $payload): void
    {
        if (! filter_var(env('PUSH_NOTIFICATIONS_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $appId = config('services.onesignal.app_id');
        $apiKey = config('services.onesignal.rest_api_key');
        if (empty($appId) || empty($apiKey)) {
            Log::debug('[PushChannel] OneSignal not configured, skipping.', ['event' => $event]);

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

        if ($externalIds === []) {
            return;
        }

        $title = (string) ($payload['title'] ?? 'Notification');
        $message = (string) ($payload['message'] ?? '');

        // Current OneSignal API: POST https://api.onesignal.com/notifications — target by
        // external_id (matches Web SDK OneSignal.login). Legacy include_external_user_ids is deprecated.
        $body = [
            'app_id' => $appId,
            'target_channel' => 'push',
            'include_aliases' => [
                'external_id' => $externalIds,
            ],
            'headings' => ['en' => $title],
            'contents' => ['en' => $message],
        ];

        $actionUrl = isset($payload['action_url']) ? trim((string) $payload['action_url']) : '';
        if ($actionUrl !== '') {
            $body['url'] = $actionUrl;
        }

        $data = [];
        if (! empty($payload['data']) && is_array($payload['data'])) {
            $data = $payload['data'];
        }
        $data['event'] = $event;
        if (! empty($payload['asset_id'])) {
            $data['asset_id'] = (string) $payload['asset_id'];
        }
        if ($data !== []) {
            $stringData = [];
            foreach ($data as $k => $v) {
                if ($v === null) {
                    continue;
                }
                $stringData[(string) $k] = is_scalar($v) ? (string) $v : json_encode($v);
            }
            if ($stringData !== []) {
                $body['data'] = $stringData;
            }
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'Authorization' => 'Key '.$apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post('https://api.onesignal.com/notifications', $body);

            if (! $response->successful()) {
                Log::warning('[PushChannel] OneSignal request failed', [
                    'event' => $event,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('[PushChannel] OneSignal exception', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
