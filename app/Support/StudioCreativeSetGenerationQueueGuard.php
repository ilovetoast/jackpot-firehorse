<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Studio Versions generation must never run heavy work on the web process in staging/production.
 * With {@code QUEUE_CONNECTION=sync}, queued jobs execute inline during the HTTP request — acceptable only
 * in {@code local} / {@code testing} for smoke tests. Elsewhere, treat sync as a hard misconfiguration.
 */
final class StudioCreativeSetGenerationQueueGuard
{
    /**
     * @param  string|null  $overrideEnvironment  For unit tests only; when null, uses {@see app()->environment()}.
     * @param  string|null  $overrideQueueConnection  For unit tests only; when null, uses {@see config('queue.default')}.
     *
     * @throws ValidationException
     */
    public static function assertStudioGenerationUsesWorkers(
        ?string $overrideEnvironment = null,
        ?string $overrideQueueConnection = null,
    ): void {
        $env = $overrideEnvironment ?? app()->environment();
        if (in_array($env, ['local', 'testing'], true)) {
            return;
        }

        $connection = $overrideQueueConnection ?? (string) config('queue.default', 'sync');
        if ($connection === 'sync') {
            Log::critical('studio_versions.generation_blocked_sync_queue', [
                'environment' => $env,
                'queue_default' => $connection,
                'message' => 'Studio Versions generation requires asynchronous workers; sync queue would run generation inline on the web server.',
            ]);

            throw ValidationException::withMessages([
                'queue' => [
                    'Studio Versions generation is unavailable: the app is configured with QUEUE_CONNECTION=sync, which would run generation on the web server. Set an async queue driver (e.g. redis) and run queue workers / Horizon.',
                ],
            ]);
        }
    }
}
