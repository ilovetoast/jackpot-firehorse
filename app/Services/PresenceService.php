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
            $pattern = $this->pattern($tenant->id, $brand?->id);
            $prefix = config('database.redis.options.prefix') ?? '';
            $redis = Redis::connection();
            $iterator = null;
            $results = [];

            do {
                $keys = $redis->scan($iterator, $pattern, 100);
                if (is_array($keys)) {
                    foreach ($keys as $key) {
                        // Scan returns physical keys (with prefix); get() expects logical key
                        $logicalKey = $prefix ? substr((string) $key, strlen($prefix)) : $key;
                        $data = $redis->get($logicalKey);
                        if ($data) {
                            $decoded = json_decode($data, true);
                            if ($decoded) {
                                $results[] = $decoded;
                            }
                        }
                    }
                }
            } while ($iterator != 0);

            return collect($results)
                ->filter()
                ->values()
                ->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected function key($tenantId, $brandId, $userId): string
    {
        return 'presence:'.$tenantId.':'.($brandId ?? 'all').':'.$userId;
    }

    /**
     * Redis keys are automatically prefixed (e.g. jackpot-database-).
     * We must include the prefix when scanning or SCAN will not match.
     */
    protected function pattern($tenantId, $brandId): string
    {
        $prefix = config('database.redis.options.prefix') ?? '';

        return $prefix.'presence:'.$tenantId.':'.($brandId ?? 'all').':*';
    }
}
