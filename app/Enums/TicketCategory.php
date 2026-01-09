<?php

namespace App\Enums;

enum TicketCategory: string
{
    case BILLING = 'billing';
    case TECHNICAL_ISSUE = 'technical_issue';
    case BUG = 'bug';
    case FEATURE_REQUEST = 'feature_request';
    case ACCOUNT_ACCESS = 'account_access';

    /**
     * Get all ticket category values.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get all ticket category names.
     */
    public static function names(): array
    {
        return array_column(self::cases(), 'name');
    }

    /**
     * Get human-readable label for the category.
     */
    public function label(): string
    {
        return match ($this) {
            self::BILLING => 'Billing',
            self::TECHNICAL_ISSUE => 'Technical Issue',
            self::BUG => 'Bug',
            self::FEATURE_REQUEST => 'Feature Request',
            self::ACCOUNT_ACCESS => 'Account/Access',
        };
    }
}
