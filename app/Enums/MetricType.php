<?php

namespace App\Enums;

/**
 * Metric Type Enum
 * 
 * Defines the types of metrics that can be tracked for assets.
 * Extensible for future metric types.
 */
enum MetricType: string
{
    /**
     * Asset download metric.
     * Tracked when a user downloads an asset.
     */
    case DOWNLOAD = 'download';

    /**
     * Asset view metric.
     * Tracked when a user views an asset (drawer, large view, etc.).
     */
    case VIEW = 'view';

    /**
     * Get all metric type values as array.
     * 
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Check if a value is a valid metric type.
     * 
     * @param string $value
     * @return bool
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }
}
