<?php

namespace App\Enums;

/**
 * The six scoring dimensions in Brand Alignment v2.
 */
enum AlignmentDimension: string
{
    case IDENTITY = 'identity';
    case COLOR = 'color';
    case TYPOGRAPHY = 'typography';
    case VISUAL_STYLE = 'visual_style';
    case COPY_VOICE = 'copy_voice';
    case CONTEXT_FIT = 'context_fit';

    public function label(): string
    {
        return match ($this) {
            self::IDENTITY => 'Identity',
            self::COLOR => 'Color',
            self::TYPOGRAPHY => 'Typography',
            self::VISUAL_STYLE => 'Visual Style',
            self::COPY_VOICE => 'Copy / Voice',
            self::CONTEXT_FIT => 'Context Fit',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
