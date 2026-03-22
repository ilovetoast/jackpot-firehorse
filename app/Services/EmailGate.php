<?php

namespace App\Services;

/**
 * Central gate for whether an email may be sent, by classification (user vs system).
 */
final class EmailGate
{
    public const TYPE_USER = 'user';

    public const TYPE_SYSTEM = 'system';

    public function canSend(string $type): bool
    {
        if ($type === self::TYPE_USER) {
            return true;
        }

        if ($type === self::TYPE_SYSTEM) {
            return config('mail.automations_enabled') === true;
        }

        return false;
    }
}
