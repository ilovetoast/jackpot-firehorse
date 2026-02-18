<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Observability v1: Measure response time, add X-Response-Time header,
 * log slow requests, optionally persist to performance_logs.
 */
class ResponseTimingMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->enabled()) {
            return $next($request);
        }

        $start = microtime(true);
        $startMemory = memory_get_usage(true);

        $response = $next($request);

        $durationMs = (int) round((microtime(true) - $start) * 1000);
        $memoryUsage = memory_get_usage(true) - $startMemory;

        $response->headers->set('X-Response-Time', "{$durationMs}ms");

        $threshold = (int) config('performance.slow_threshold_ms', 1000);
        if ($durationMs >= $threshold) {
            Log::warning('[Slow Request]', [
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'duration_ms' => $durationMs,
                'user_id' => $request->user()?->id,
            ]);

            if ($this->shouldPersist()) {
                $this->persistLog($request, $durationMs, $memoryUsage);
            }
        }

        return $response;
    }

    protected function enabled(): bool
    {
        return (bool) config('performance.enabled', false);
    }

    protected function shouldPersist(): bool
    {
        return (bool) config('performance.persist_slow_logs', false);
    }

    protected function persistLog(Request $request, int $durationMs, int $memoryUsage): void
    {
        try {
            if (!class_exists(\App\Models\PerformanceLog::class)) {
                return;
            }
            \App\Models\PerformanceLog::create([
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'duration_ms' => $durationMs,
                'user_id' => $request->user()?->id,
                'memory_usage' => $memoryUsage,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[PerformanceLog] Failed to persist', ['error' => $e->getMessage()]);
        }
    }
}
