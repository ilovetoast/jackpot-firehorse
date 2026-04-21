<?php

namespace App\Services\Studio;

/**
 * Builds deterministic combination keys for Studio Versions generation (color × scene × format).
 *
 * Combination segments (when present): {@code c:colorId}, {@code s:sceneId}, {@code f:formatId}.
 */
final class CreativeSetGenerationPlanner
{
    /**
     * @param  array<int, array{id: string, label: string, hex?: string|null}>  $colors
     * @param  array<int, array{id: string, label: string, instruction: string}>  $scenes
     * @param  array<int, array{id: string, label: string, width: int, height: int}>  $formats
     * @param  array<int, string>|null  $selectedKeys  if null, use full cartesian product
     * @return array{keys: array<int, string>, snapshot: array<string, mixed>}
     */
    public function plan(
        int $baselineCompositionId,
        array $colors,
        array $scenes,
        array $formats,
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
        $formatsById = [];
        foreach ($formats as $f) {
            $formatsById[(string) $f['id']] = $f;
        }

        $colorEntries = $colors !== [] ? $colors : [null];
        $sceneEntries = $scenes !== [] ? $scenes : [null];
        $formatEntries = $formats !== [] ? $formats : [null];

        $keys = [];
        foreach ($colorEntries as $c) {
            foreach ($sceneEntries as $s) {
                foreach ($formatEntries as $f) {
                    if ($c === null && $s === null && $f === null) {
                        continue;
                    }
                    $parts = [];
                    if ($c !== null) {
                        $parts[] = 'c:'.$c['id'];
                    }
                    if ($s !== null) {
                        $parts[] = 's:'.$s['id'];
                    }
                    if ($f !== null) {
                        $parts[] = 'f:'.$f['id'];
                    }
                    if ($parts === []) {
                        continue;
                    }
                    $keys[] = implode('|', $parts);
                }
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
            'formats_by_id' => $formatsById,
        ];

        return ['keys' => $keys, 'snapshot' => $snapshot];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{color: ?array<string, mixed>, scene: ?array<string, mixed>, format: ?array<string, mixed>}
     */
    public function parseCombinationKey(string $key, array $snapshot): array
    {
        $colorsById = is_array($snapshot['colors_by_id'] ?? null) ? $snapshot['colors_by_id'] : [];
        $scenesById = is_array($snapshot['scenes_by_id'] ?? null) ? $snapshot['scenes_by_id'] : [];
        $formatsById = is_array($snapshot['formats_by_id'] ?? null) ? $snapshot['formats_by_id'] : [];
        $color = null;
        $scene = null;
        $format = null;
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
            if (str_starts_with($part, 'f:')) {
                $id = substr($part, 2);
                $format = is_array($formatsById[$id] ?? null) ? $formatsById[$id] : null;
            }
        }

        return ['color' => $color, 'scene' => $scene, 'format' => $format];
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
