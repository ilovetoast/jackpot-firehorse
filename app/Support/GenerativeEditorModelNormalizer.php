<?php

namespace App\Support;

/**
 * Maps retired generative-image API / registry ids to current keys in {@see config('ai.models')}.
 */
final class GenerativeEditorModelNormalizer
{
    public static function normalizeRegistryKey(string $key): string
    {
        return match ($key) {
            'gemini-1.5-flash-image' => 'gemini-2.5-flash-image',
            default => $key,
        };
    }

    public static function normalizeApiModelId(string $provider, string $apiModel): string
    {
        if (strtolower($provider) === 'gemini' && $apiModel === 'gemini-1.5-flash-image') {
            return 'gemini-2.5-flash-image';
        }

        return $apiModel;
    }
}
