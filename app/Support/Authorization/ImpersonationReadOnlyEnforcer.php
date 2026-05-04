<?php

namespace App\Support\Authorization;

use App\Models\User;
use App\Services\ImpersonationService;

/**
 * Central read-only enforcement for Tier 1 (and Tier 2 assisted stub) impersonation.
 *
 * Policy methods and Spatie-style permission strings are evaluated here before
 * individual policies run. Full impersonation does not register this hook result.
 */
class ImpersonationReadOnlyEnforcer
{
    public static function gateBefore(?User $user, string $ability): ?bool
    {
        if (! $user) {
            return null;
        }

        if (! app(ImpersonationService::class)->isReadOnly()) {
            return null;
        }

        if (self::isReadOnlyAllowedAbility($ability)) {
            return null;
        }

        return false;
    }

    public static function isReadOnlyAllowedAbility(string $ability): bool
    {
        $allow = array_merge(
            config('impersonation.read_only_abilities', []),
            config('impersonation.read_only_extra_abilities', [])
        );

        if (in_array($ability, $allow, true)) {
            return true;
        }

        if (str_ends_with($ability, '.view')) {
            return true;
        }

        return false;
    }
}
