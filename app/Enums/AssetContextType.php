<?php

namespace App\Enums;

/**
 * Creative context for Brand Intelligence — used to match assets to style references.
 */
enum AssetContextType: string
{
    case PRODUCT_HERO = 'product_hero';
    case LIFESTYLE = 'lifestyle';
    case DIGITAL_AD = 'digital_ad';
    case SOCIAL_POST = 'social_post';
    case LOGO_ONLY = 'logo_only';
    case OTHER = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function tryFromString(?string $raw): ?self
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $n = strtolower(trim($raw));

        return self::tryFrom($n);
    }
}
