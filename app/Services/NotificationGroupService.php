<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Notification collapsing: group duplicate events by type + brand + date.
 * Uses upsert instead of insert to prevent notification spam.
 */
class NotificationGroupService
{
    protected const META_ITEMS_LIMIT = 20;

    /**
     * Create or update a grouped notification.
     *
     * @param int $userId
     * @param string $type e.g. 'asset.submitted', 'brand_research.ready', 'download.ready'
     * @param array $data Notification payload (must include brand_id when applicable)
     */
    public function upsert(int $userId, string $type, array $data): void
    {
        $brandId = $data['brand_id'] ?? 0;
        $groupKey = $this->buildGroupKey($type, $brandId);

        $existing = Notification::where('user_id', $userId)
            ->where('group_key', $groupKey)
            ->first();

        // Clean up any legacy ungrouped notifications of the same type for this user
        if (! $existing) {
            $legacyOrphans = Notification::where('user_id', $userId)
                ->where('type', $type)
                ->whereNull('group_key')
                ->get();

            if ($legacyOrphans->isNotEmpty()) {
                $legacyOrphans->each->delete();
            }
        }

        $item = [
            'brand_id' => $data['brand_id'] ?? null,
            'brand_name' => $data['brand_name'] ?? null,
            'asset_name' => $data['asset_name'] ?? null,
            'actor_name' => $data['actor_name'] ?? null,
            'download_title' => $data['download_title'] ?? null,
            'action_url' => $data['action_url'] ?? null,
            'asset_id' => $data['asset_id'] ?? null,
            'download_id' => $data['download_id'] ?? null,
            'tenant_id' => $data['tenant_id'] ?? null,
            'tenant_name' => $data['tenant_name'] ?? null,
            'created_at' => now()->toISOString(),
        ];

        if ($existing) {
            $meta = $existing->meta ?? ['items' => []];
            $items = $meta['items'] ?? [];
            $items[] = $item;
            $items = array_slice($items, -self::META_ITEMS_LIMIT);

            $existing->update([
                'count' => $existing->count + 1,
                'latest_at' => now(),
                'data' => array_merge($existing->data ?? [], $data),
                'meta' => ['items' => $items],
            ]);
        } else {
            Notification::create([
                'user_id' => $userId,
                'type' => $type,
                'group_key' => $groupKey,
                'count' => 1,
                'latest_at' => now(),
                'data' => $data,
                'meta' => ['items' => [$item]],
            ]);
        }
    }

    protected function buildGroupKey(string $type, int|string $brandId): string
    {
        return "{$type}_{$brandId}_" . now()->format('Y-m-d');
    }
}
