<?php

namespace App\Enums;

enum AssetType: string
{
    case BASIC = 'basic';
    case MARKETING = 'marketing';

    /**
     * Get all asset type values.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get all asset type names.
     */
    public static function names(): array
    {
        return array_column(self::cases(), 'name');
    }
}
