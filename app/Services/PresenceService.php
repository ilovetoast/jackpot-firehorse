<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Redis-based presence for tenant/brand. TTL-only, no DB writes.
 *
 * Key isolation:
 * - In brand context: presence:{tenantId}:{brandId}:{userId} — only users in this brand
 * - At tenant level: presence:{tenantId}:all:{userId} — tenant-wide users
 * Never mix: brand A users never appear in brand B's online list.
 */
class PresenceService
{
    protected int $ttl = 90;

    public function heartbeat($user, $tenant, $brand = null, $page = null): void
    {
        try {
            $key = $this->key($tenant->id, $brand?->id, $user->id);

            Redis::setex($key, $this->ttl, json_encode([
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->getRoleForTenant($tenant),
                'page' => $page,
                'last_seen' => now()->timestamp,
            ]));
        } catch (\Throwable $e) {
            // Fail silently in local if Redis not available
            Log::debug('Presence heartbeat skipped: '.$e->getMessage());
        }
    }

    public function online($tenant, $brand = null): array
    {
        try {
            $pattern = '*presence:'.$tenant->id.':'.($brand?->id ?? 'all').':*';

            $redis = Redis::connection();
            $cursor = 0;
            $results = [];

            do {
                $result = $redis->scan($cursor, $pattern, 100);

                if (! is_array($result) || count($result) < 2) {
                    break;
                }

                $cursor = $result[0];
                $keys = $result[1];

                if (! empty($keys)) {
                    foreach ($keys as $key) {
                        $prefix = $redis->getOptions()['prefix'] ?? '';

                        if ($prefix && str_starts_with($key, $prefix)) {
                            $key = substr($key, strlen($prefix));
                        }

                        $data = $redis->get($key);

                        if ($data) {
                            $decoded = json_decode($data, true);
                            if (is_array($decoded)) {
                                $results[] = $decoded;
                            }
                        }
                    }
                }
            } while ($cursor != 0);

            return $results;
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected function key($tenantId, $brandId, $userId): string
    {
        return 'presence:'.$tenantId.':'.($brandId ?? 'all').':'.$userId;
    }
}
