<?php

namespace App\Enums;

/**
 * Link Designation Enum
 * 
 * Defines how a diagnostic link relates to a ticket.
 * 
 * Used to categorize ticket links for better organization:
 * - primary: The main diagnostic evidence for this ticket
 * - related: Additional context or related information
 * - duplicate: Links to duplicate tickets or issues
 */
enum LinkDesignation: string
{
    case PRIMARY = 'primary';
    case RELATED = 'related';
    case DUPLICATE = 'duplicate';

    /**
     * Get all designation values.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get all designation names.
     */
    public static function names(): array
    {
        return array_column(self::cases(), 'name');
    }

    /**
     * Get human-readable label for designation.
     */
    public function label(): string
    {
        return ucfirst($this->value);
    }
}
