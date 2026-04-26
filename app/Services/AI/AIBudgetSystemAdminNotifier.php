<?php

namespace App\Services\AI;

use App\Mail\SystemAiBudgetCapReachedMail;
use App\Mail\SystemAiBudgetWarningMail;
use App\Models\AIBudget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Throttled email to site operators for the system-wide (platform) monthly AI budget only.
 * Tenant credits / plan limits are separate and do not use this notifier.
 */
final class AIBudgetSystemAdminNotifier
{
    private const WARNING_CACHE_PREFIX = 'ai:sys:budget:admin:warn:mail:';

    private const CAP_CACHE_PREFIX = 'ai:sys:budget:admin:cap:mail:';

    /** At most one warning email per 24h per app env (crosses threshold). */
    private const WARNING_TTL_SECONDS = 86400;

    /** At most one cap email per hour per app env (flood of blocked requests). */
    private const CAP_TTL_SECONDS = 3600;

    public function notifyApproaching(
        AIBudget $budget,
        string $budgetEnvironment,
        float $currentUsage,
        float $capUsd,
        int $warningThresholdPercent
    ): void {
        if ($budget->budget_type !== 'system') {
            return;
        }

        $emails = config('mail.admin_recipients', []);
        if (! is_array($emails) || $emails === []) {
            return;
        }

        $appEnv = (string) config('app.env', 'local');
        $key = self::WARNING_CACHE_PREFIX.$appEnv.':'.now()->format('Y-m-d');
        if (! Cache::add($key, 1, self::WARNING_TTL_SECONDS)) {
            return;
        }

        try {
            $mailable = new SystemAiBudgetWarningMail(
                $appEnv,
                $budgetEnvironment,
                $capUsd,
                $currentUsage,
                $warningThresholdPercent,
            );
            Mail::to($emails)->send($mailable);
        } catch (Throwable $e) {
            Log::error('[AIBudgetSystemAdmin] Failed to send warning mail', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function notifyCapBlocked(
        AIBudget $budget,
        string $budgetEnvironment,
        float $capUsd,
        float $currentUsage,
        float $projectedUsage,
        float $estimatedCost
    ): void {
        if ($budget->budget_type !== 'system') {
            return;
        }

        $emails = config('mail.admin_recipients', []);
        if (! is_array($emails) || $emails === []) {
            return;
        }

        $appEnv = (string) config('app.env', 'local');
        $key = self::CAP_CACHE_PREFIX.$appEnv.':'.now()->format('Y-m-d-H');
        if (! Cache::add($key, 1, self::CAP_TTL_SECONDS)) {
            return;
        }

        try {
            $mailable = new SystemAiBudgetCapReachedMail(
                $appEnv,
                $budgetEnvironment,
                $capUsd,
                $currentUsage,
                $projectedUsage,
                $estimatedCost,
            );
            Mail::to($emails)->send($mailable);
        } catch (Throwable $e) {
            Log::error('[AIBudgetSystemAdmin] Failed to send cap-reached mail', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
