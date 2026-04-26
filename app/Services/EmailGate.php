<?php

namespace App\Services;

/**
 * Central gate for whether an email may be sent, by classification (user vs system).
 *
 * {@see self::TYPE_OPERATIONS} is for critical site-operator mail (e.g. provider quota)
 * to {@see config('mail.admin_recipients')}. It always sends, even when
 * {@see config('mail.automations_enabled')} is false.
 */
final class EmailGate
{
    public const TYPE_USER = 'user';

    public const TYPE_SYSTEM = 'system';

    public const TYPE_OPERATIONS = 'operations';

    public function canSend(string $type): bool
    {
        if ($type === self::TYPE_USER || $type === self::TYPE_OPERATIONS) {
            return true;
        }

        if ($type === self::TYPE_SYSTEM) {
            return config('mail.automations_enabled') === true;
        }

        return false;
    }
}
