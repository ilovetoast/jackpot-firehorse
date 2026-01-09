<?php

namespace App\Enums;

enum TicketTeam: string
{
    case SUPPORT = 'support';
    case ADMIN = 'admin';
    case ENGINEERING = 'engineering';

    /**
     * Get all ticket team values.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get all ticket team names.
     */
    public static function names(): array
    {
        return array_column(self::cases(), 'name');
    }
}
