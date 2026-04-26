<?php

namespace App\Services\AI;

use App\Exceptions\AIQuotaExceededException;
use App\Mail\AIProviderQuotaExceededMail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Throttled email to site operators when an upstream provider reports quota/billing limits.
 * Cooldown is per environment + provider to avoid a flood of identical alerts (e.g. many queued jobs).
 */
final class AIQuotaExceededNotifier
{
    public function notify(AIQuotaExceededException $exception): void
    {
        $emails = config('mail.admin_recipients', []);
        if (! is_array($emails) || $emails === []) {
            Log::info('[AIQuotaExceeded] No ADMIN_EMAIL configured; skipping operator mail', [
                'message' => $exception->getMessage(),
                'provider' => $exception->provider,
            ]);

            return;
        }

        $providerKey = (string) ($exception->provider ?? 'unknown');
        $envKey = (string) config('app.env', 'local');
        $cd = max(60, (int) config('ai.quota_exceeded.notify_cooldown_seconds', 3600));
        $cacheKey = 'ai:quota:mail:'.$envKey.':'.md5($providerKey);

        if (! Cache::add($cacheKey, 1, now()->addSeconds($cd))) {
            return;
        }

        try {
            $mailable = new AIProviderQuotaExceededMail(
                $exception->getMessage(),
                $exception->provider,
            );
            Mail::to($emails)->send($mailable);
        } catch (Throwable $e) {
            Log::error('[AIQuotaExceeded] Failed to send operator mail', [
                'error' => $e->getMessage(),
                'provider' => $exception->provider,
            ]);
        }
    }
}
