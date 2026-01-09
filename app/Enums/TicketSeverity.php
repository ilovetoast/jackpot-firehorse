<?php

namespace App\Enums;

/**
 * Ticket Severity Enum
 * 
 * Defines severity levels for internal engineering tickets.
 * 
 * Usage Guidelines:
 * - P0 (Critical Outage): System is down or major functionality is completely broken
 *   - All hands on deck, immediate response required
 *   - Affects all or most users
 *   - Examples: Complete site outage, payment processing down, data loss
 * 
 * - P1 (Major Issue): Significant functionality impaired but system is operational
 *   - High priority, should be addressed within hours
 *   - Affects many users or critical workflows
 *   - Examples: Major feature broken, significant performance degradation, security vulnerability
 * 
 * - P2 (Moderate Issue): Some functionality affected, workarounds available
 *   - Normal priority, should be addressed within days
 *   - Affects some users or non-critical features
 *   - Examples: Minor feature broken, moderate performance issues, non-critical bugs
 * 
 * - P3 (Minor Issue): Low impact, cosmetic or edge case issues
 *   - Low priority, can be addressed in next release cycle
 *   - Affects few users or edge cases
 *   - Examples: UI glitches, minor bugs, documentation issues
 * 
 * Note: Severity is only applicable to internal engineering tickets
 * (type=internal, assigned_team=engineering).
 */
enum TicketSeverity: string
{
    case P0 = 'P0'; // Critical outage
    case P1 = 'P1'; // Major issue
    case P2 = 'P2'; // Moderate issue
    case P3 = 'P3'; // Minor issue

    /**
     * Get all severity values.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get all severity names.
     */
    public static function names(): array
    {
        return array_column(self::cases(), 'name');
    }

    /**
     * Get human-readable label for severity.
     */
    public function label(): string
    {
        return match($this) {
            self::P0 => 'P0 - Critical Outage',
            self::P1 => 'P1 - Major Issue',
            self::P2 => 'P2 - Moderate Issue',
            self::P3 => 'P3 - Minor Issue',
        };
    }
}
