<?php

namespace App\Enums;

/**
 * View Type Enum
 * 
 * Defines the types of views that can be tracked for assets.
 * Used with VIEW metric type to differentiate view contexts.
 * Extensible for future view types.
 */
enum ViewType: string
{
    /**
     * Drawer view - when user opens the asset details drawer/panel.
     */
    case DRAWER = 'drawer';

    /**
     * Large view - when user opens the large/zoom modal view.
     */
    case LARGE_VIEW = 'large_view';

    /**
     * Get all view type values as array.
     * 
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Check if a value is a valid view type.
     * 
     * @param string $value
     * @return bool
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }
}
