<?php

namespace App\Support\Billing;

use App\Models\Tenant;
use App\Services\PlanService;

/**
 * Structured plan-limit metadata for upgrade UX (upload size and future quota reasons).
 * Values come from PlanService / config('plans') only.
 */
class PlanLimitUpgradePayload
{
    /** Config uses very large MB caps as practical "unlimited" for uploads/downloads. */
    public const UNLIMITED_NUMERIC_THRESHOLD_MB = 999000;

    public static function isUnlimitedMb(?int $mb): bool
    {
        return $mb === null || $mb >= self::UNLIMITED_NUMERIC_THRESHOLD_MB;
    }

    public static function mbFromBytes(int $bytes): float
    {
        return round($bytes / 1024 / 1024, 2);
    }

    public static function formatByteSizeLabel(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return round($bytes / 1024 / 1024 / 1024, 2).' GB';
        }
        if ($bytes >= 1024 * 1024) {
            return round($bytes / 1024 / 1024, 2).' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 2).' KB';
        }

        return $bytes.' B';
    }

    public static function planSolvesMaxUploadMb(?int $planMaxUploadMb, float $attemptedMb): bool
    {
        if (self::isUnlimitedMb($planMaxUploadMb)) {
            return true;
        }

        return $planMaxUploadMb >= $attemptedMb;
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildForUploadSizeExceeded(Tenant $tenant, int $attemptedBytes): array
    {
        $planService = app(PlanService::class);
        $planKey = $planService->getCurrentPlan($tenant);
        $planConfig = config("plans.{$planKey}", config('plans.free'));
        $planDisplayName = (string) ($planConfig['name'] ?? $planKey);
        $maxBytes = $planService->getMaxUploadSize($tenant);
        $allowedMb = self::mbFromBytes($maxBytes);
        $attemptedMb = self::mbFromBytes($attemptedBytes);

        $upgradeUrl = route('billing.plans', [
            'reason' => 'max_upload_size',
            'current_plan' => $planKey,
            'attempted' => $attemptedMb,
            'limit' => $allowedMb,
        ]);

        return [
            'error_code' => 'plan_limit_exceeded',
            'limit_key' => 'max_upload_size',
            'current_plan_key' => $planKey,
            'current_plan_name' => $planDisplayName,
            'attempted_value' => $attemptedMb,
            'attempted_value_label' => self::formatByteSizeLabel($attemptedBytes),
            'allowed_value' => $allowedMb,
            'allowed_value_label' => self::formatByteSizeLabel($maxBytes),
            'upgrade_url' => $upgradeUrl,
        ];
    }
}
