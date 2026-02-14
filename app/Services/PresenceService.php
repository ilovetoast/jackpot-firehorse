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

            $cursor = null;
            $results = [];

            do {
                [$cursor, $keys] = Redis::scan($cursor, [
                    'match' => $pattern,
                    'count' => 100,
                ]);

                foreach ($keys as $key) {
                    $data = Redis::get($key);
                    if ($data) {
                        $decoded = json_decode($data, true);
                        if ($decoded) {
                            $results[] = $decoded;
                        }
                    }
                }
            } while ($cursor != 0);

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

    protected function pattern($tenantId, $brandId): string
    {
        return 'presence:'.$tenantId.':'.($brandId ?? 'all').':*';
    }
}
