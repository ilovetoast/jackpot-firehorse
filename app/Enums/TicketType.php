<?php

namespace App\Enums;

enum TicketType: string
{
    case TENANT = 'tenant';
    case TENANT_INTERNAL = 'tenant_internal';
    case INTERNAL = 'internal';

    /**
     * Get all ticket type values.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get all ticket type names.
     */
    public static function names(): array
    {
        return array_column(self::cases(), 'name');
    }
}
