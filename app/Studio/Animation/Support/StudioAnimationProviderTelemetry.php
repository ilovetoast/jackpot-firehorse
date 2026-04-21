<?php

namespace App\Studio\Animation\Support;

final class StudioAnimationProviderTelemetry
{
    /**
     * @param  array<string, mixed>|null  $existing
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    public static function merge(?array $existing, array $entry): array
    {
        $base = is_array($existing) ? $existing : [];
        $trace = $base['internal_pipeline_trace'] ?? [];
        if (! is_array($trace)) {
            $trace = [];
        }
        $trace[] = array_merge(['at' => now()->toIso8601String()], $entry);
        $base['internal_pipeline_trace'] = $trace;

        return $base;
    }
}
