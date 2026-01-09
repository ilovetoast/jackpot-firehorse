<?php

namespace App\Enums;

enum TicketStatus: string
{
    case OPEN = 'open';
    case WAITING_ON_USER = 'waiting_on_user';
    case WAITING_ON_SUPPORT = 'waiting_on_support';
    case IN_PROGRESS = 'in_progress';
    case BLOCKED = 'blocked';
    case RESOLVED = 'resolved';
    case CLOSED = 'closed';

    /**
     * Get all ticket status values.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get all ticket status names.
     */
    public static function names(): array
    {
        return array_column(self::cases(), 'name');
    }
}
