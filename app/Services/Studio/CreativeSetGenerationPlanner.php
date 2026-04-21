<?php

namespace App\Services\Studio;

/**
 * Builds deterministic combination keys for Studio Versions generation (color × scene).
 */
final class CreativeSetGenerationPlanner
{
    /**
     * @param  array<int, array{id: string, label: string, hex?: string|null}>  $colors
     * @param  array<int, array{id: string, label: string, instruction: string}>  $scenes
     * @param  array<int, string>|null  $selectedKeys  if null, use full cartesian product
     * @return array{keys: array<int, string>, snapshot: array<string, mixed>}
     */
    public function plan(
        int $baselineCompositionId,
        array $colors,
        array $scenes,
        ?array $selectedKeys,
    ): array {
        $colorsById = [];
        foreach ($colors as $c) {
            $colorsById[(string) $c['id']] = $c;
        }
        $scenesById = [];
        foreach ($scenes as $s) {
            $scenesById[(string) $s['id']] = $s;
        }

        $keys = [];
        if ($colors !== [] && $scenes !== []) {
            foreach ($colors as $c) {
                foreach ($scenes as $s) {
                    $keys[] = 'c:'.$c['id'].'|s:'.$s['id'];
                }
            }
        } elseif ($colors !== []) {
            foreach ($colors as $c) {
                $keys[] = 'c:'.$c['id'];
            }
        } elseif ($scenes !== []) {
            foreach ($scenes as $s) {
                $keys[] = 's:'.$s['id'];
            }
        }

        $keys = array_values(array_unique($keys));

        if ($selectedKeys !== null && $selectedKeys !== []) {
            $allow = array_flip($selectedKeys);
            $keys = array_values(array_filter($keys, static fn (string $k) => isset($allow[$k])));
        }

        $snapshot = [
            'baseline_composition_id' => $baselineCompositionId,
            'colors_by_id' => $colorsById,
            'scenes_by_id' => $scenesById,
        ];

        return ['keys' => $keys, 'snapshot' => $snapshot];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{color: ?array<string, mixed>, scene: ?array<string, mixed>}
     */
    public function parseCombinationKey(string $key, array $snapshot): array
    {
        $colorsById = is_array($snapshot['colors_by_id'] ?? null) ? $snapshot['colors_by_id'] : [];
        $scenesById = is_array($snapshot['scenes_by_id'] ?? null) ? $snapshot['scenes_by_id'] : [];
        $color = null;
        $scene = null;
        foreach (explode('|', $key) as $part) {
            $part = trim($part);
            if (str_starts_with($part, 'c:')) {
                $id = substr($part, 2);
                $color = is_array($colorsById[$id] ?? null) ? $colorsById[$id] : null;
            }
            if (str_starts_with($part, 's:')) {
                $id = substr($part, 2);
                $scene = is_array($scenesById[$id] ?? null) ? $scenesById[$id] : null;
            }
        }

        return ['color' => $color, 'scene' => $scene];
    }

    /**
     * @param  array<string, mixed>  $color
     * @param  array<string, mixed>  $scene
     */
    public function buildInstruction(?array $color, ?array $scene, string $colorTemplate): string
    {
        $parts = [];
        if ($scene !== null && isset($scene['instruction'])) {
            $parts[] = trim((string) $scene['instruction']);
        }
        if ($color !== null) {
            $label = trim((string) ($color['label'] ?? 'selected'));
            $hex = trim((string) ($color['hex'] ?? ''));
            if ($hex === '') {
                $hex = '(use best judgment)';
            }
            $parts[] = str_replace([':label', ':hex'], [$label, $hex], $colorTemplate);
        }

        return implode("\n\n", array_filter($parts));
    }
}
