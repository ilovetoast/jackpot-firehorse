<?php

namespace App\Services\Notifications\Channels;

use App\Models\Tenant;
use App\Services\FeatureGate;
use App\Services\NotificationGroupService;
use App\Services\Notifications\Contracts\NotificationChannel;

/**
 * Persists to the existing grouped in-app notification feed via {@link NotificationGroupService}.
 */
final class InAppChannel implements NotificationChannel
{
    public function __construct(
        protected NotificationGroupService $notificationGroupService
    ) {}

    public function send(string $event, array $payload): void
    {
        $tenantId = isset($payload['tenant_id']) ? (int) $payload['tenant_id'] : null;
        if ($tenantId) {
            $tenant = Tenant::query()->find($tenantId);
            if ($tenant && ! app(FeatureGate::class)->notificationsEnabled($tenant)) {
                return;
            }
        }

        $userIds = $payload['user_ids'] ?? [];
        if (! is_array($userIds) || $userIds === []) {
            return;
        }

        $data = [
            'brand_id' => $payload['brand_id'] ?? 0,
            'brand_name' => $payload['brand_name'] ?? 'Brand',
            'tenant_id' => $payload['tenant_id'] ?? null,
            'tenant_name' => $payload['tenant_name'] ?? null,
            'title' => $payload['title'] ?? 'Notification',
            'body' => $payload['message'] ?? '',
            'asset_id' => $payload['asset_id'] ?? null,
            'action_url' => $payload['action_url'] ?? null,
        ];

        foreach ($userIds as $userId) {
            $uid = (int) $userId;
            if ($uid <= 0) {
                continue;
            }
            $this->notificationGroupService->upsert($uid, $event, $data);
        }
    }
}
