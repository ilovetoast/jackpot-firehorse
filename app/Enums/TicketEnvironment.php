<?php

namespace App\Enums;

/**
 * Ticket Environment Enum
 * 
 * Defines the environment where an engineering issue occurs.
 * 
 * Used for internal engineering tickets to track where issues are happening.
 */
enum TicketEnvironment: string
{
    case PRODUCTION = 'production';
    case STAGING = 'staging';
    case DEVELOPMENT = 'development';

    /**
     * Get all environment values.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get all environment names.
     */
    public static function names(): array
    {
        return array_column(self::cases(), 'name');
    }

    /**
     * Get human-readable label for environment.
     */
    public function label(): string
    {
        return ucfirst($this->value);
    }
}
