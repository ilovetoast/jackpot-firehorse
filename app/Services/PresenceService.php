<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

class PresenceService
{
    protected int $ttl = 90;

    protected function userKey(int $tenantId, ?int $brandId, int $userId): string
    {
        return 'presence:user:'.$tenantId.':'.($brandId ?? 'all').':'.$userId;
    }

    protected function indexKey(int $tenantId, ?int $brandId): string
    {
        return 'presence:index:'.$tenantId.':'.($brandId ?? 'all');
    }

    public function heartbeat($user, $tenant, $brand = null, array $payload = []): void
    {
        try {
            $redis = Redis::connection();

            $userKey = $this->userKey($tenant->id, $brand?->id, $user->id);
            $indexKey = $this->indexKey($tenant->id, $brand?->id);

            $data = array_merge([
                'id' => $user->id,
                'name' => $user->name,
                'role' => method_exists($user, 'getRoleForTenant')
                    ? $user->getRoleForTenant($tenant)
                    : null,
                'page' => $payload['page'] ?? null,
                'last_seen' => now()->timestamp,
            ], $payload);

            $redis->setex($userKey, $this->ttl, json_encode($data));
            $redis->sadd($indexKey, $user->id);
        } catch (\Throwable $e) {
            // Silent fail — presence is non-critical
        }
    }

    public function online($tenant, $brand = null): array
    {
        try {
            $redis = Redis::connection();

            $indexKey = $this->indexKey($tenant->id, $brand?->id);
            $userIds = $redis->smembers($indexKey);

            if (empty($userIds)) {
                return [];
            }

            $results = [];

            foreach ($userIds as $userId) {
                $userKey = $this->userKey($tenant->id, $brand?->id, (int) $userId);
                $data = $redis->get($userKey);

                if ($data) {
                    $decoded = json_decode($data, true);
                    if (is_array($decoded)) {
                        $results[] = $decoded;
                    }
                } else {
                    $redis->srem($indexKey, $userId);
                }
            }

            return array_values($results);
        } catch (\Throwable $e) {
            return [];
        }
    }
}
