<?php

namespace App\Support\BrandDNA;

/**
 * Headline appearance feature catalog (config-driven) for typography guidelines.
 */
class HeadlineAppearanceCatalog
{
    /**
     * @return array<int, array{id: string, label: string, description: string}>
     */
    public static function all(): array
    {
        return config('headline_appearance.options', []);
    }

    /**
     * @return array<int, array{id: string, label: string, description: string}>
     */
    public static function forFrontend(): array
    {
        return array_values(array_map(fn (array $o) => [
            'id' => $o['id'],
            'label' => $o['label'],
            'description' => $o['description'] ?? '',
        ], self::all()));
    }

    /**
     * @return list<string>
     */
    public static function validIds(): array
    {
        return array_values(array_filter(array_column(self::all(), 'id')));
    }

    /**
     * @param  list<mixed>|null  $input
     * @return list<string>
     */
    public static function normalizeFeatures(?array $input): array
    {
        if (! is_array($input)) {
            return [];
        }
        $valid = array_flip(self::validIds());
        $out = [];
        foreach ($input as $id) {
            if (is_string($id) && $id !== '' && isset($valid[$id])) {
                $out[] = $id;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Comma-separated IDs for Claude prompts.
     */
    public static function idsForPrompt(): string
    {
        return implode(', ', self::validIds());
    }
}
