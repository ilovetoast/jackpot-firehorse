<?php

namespace App\Support;

enum ThumbnailMode: string
{
    case Original = 'original';
    case Preferred = 'preferred';
    case Enhanced = 'enhanced';
    /** AI context-aware presentation still derived from preferred (else original) thumbnail */
    case Presentation = 'presentation';

    public static function tryFromLoose(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::tryFrom(strtolower(trim($value)));
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function normalize(string $value): string
    {
        $e = self::tryFromLoose($value);
        if ($e === null) {
            throw new \InvalidArgumentException("Invalid thumbnail mode: {$value}");
        }

        return $e->value;
    }

    public static function default(): string
    {
        return self::Original->value;
    }
}
