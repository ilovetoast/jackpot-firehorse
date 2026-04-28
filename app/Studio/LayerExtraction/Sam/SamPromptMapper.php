<?php

namespace App\Studio\LayerExtraction\Sam;

/**
 * Maps editor prompts to vendor-agnostic request payloads (SAM / future API bodies).
 * TODO: connect {@see \App\Studio\LayerExtraction\Providers\SamStudioLayerExtractionProvider} to a real HTTP
 *       transport that POSTs these structures without exposing vendor-specific fields in HTTP controllers.
 */
final class SamPromptMapper
{
    /**
     * @return array<string, mixed>
     */
    public static function forAuto(int $sourceWidth, int $sourceHeight): array
    {
        return [
            'mode' => 'auto',
            'image_size' => ['width' => $sourceWidth, 'height' => $sourceHeight],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function forPoint(float $x, float $y, int $sourceWidth, int $sourceHeight): array
    {
        return [
            'mode' => 'point',
            'positive_points' => [
                ['x' => $x, 'y' => $y],
            ],
            'image_size' => ['width' => $sourceWidth, 'height' => $sourceHeight],
        ];
    }

    /**
     * @param  list<array{x: float, y: float}>  $positives
     * @param  list<array{x: float, y: float}>  $negatives
     * @return array<string, mixed>
     */
    public static function forRefine(
        array $positives,
        array $negatives,
        int $sourceWidth,
        int $sourceHeight,
    ): array {
        return [
            'mode' => 'refine',
            'positive_points' => $positives,
            'negative_points' => $negatives,
            'image_size' => ['width' => $sourceWidth, 'height' => $sourceHeight],
        ];
    }

    /**
     * @param  array{x: float, y: float, width: float, height: float}  $box
     * @return array<string, mixed>
     */
    public static function forBox(
        array $box,
        int $sourceWidth,
        int $sourceHeight,
    ): array {
        return [
            'mode' => 'box',
            'boxes' => [
                [
                    'x' => $box['x'],
                    'y' => $box['y'],
                    'width' => $box['width'],
                    'height' => $box['height'],
                ],
            ],
            'image_size' => ['width' => $sourceWidth, 'height' => $sourceHeight],
        ];
    }
}
