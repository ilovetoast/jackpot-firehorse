<?php

namespace App\Enums;

/**
 * Ticket Component Enum
 * 
 * Defines the system component affected by an engineering issue.
 * 
 * Used for internal engineering tickets to categorize issues by system area.
 */
enum TicketComponent: string
{
    case API = 'api';
    case WEB = 'web';
    case WORKER = 'worker';
    case BILLING = 'billing';
    case INTEGRATIONS = 'integrations';

    /**
     * Get all component values.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get all component names.
     */
    public static function names(): array
    {
        return array_column(self::cases(), 'name');
    }

    /**
     * Get human-readable label for component.
     */
    public function label(): string
    {
        return ucfirst($this->value);
    }
}
