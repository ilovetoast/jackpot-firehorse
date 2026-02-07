<?php

namespace App\Support;

use Illuminate\Support\Facades\Redis;

class AdminLogStream
{
    /**
     * Push a log entry to a rolling Redis-backed stream.
     * Keys: admin_logs:web, admin_logs:worker
     * Keeps last 200 entries. Fails silently if Redis is unavailable (e.g. local).
     */
    public static function push(string $stream, array $payload): void
    {
        try {
            $key = "admin_logs:{$stream}";

            Redis::pipeline(function ($pipe) use ($key, $payload) {
                $pipe->lpush($key, json_encode([
                    'timestamp' => now()->toIso8601String(),
                    ...$payload,
                ]));

                // Keep last 200 entries only
                $pipe->ltrim($key, 0, 199);
            });
        } catch (\Throwable $e) {
            // Local may not have Redis; avoid breaking the app
            \Illuminate\Support\Facades\Log::debug('[AdminLogStream] Redis unavailable, skipping', [
                'stream' => $stream,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
