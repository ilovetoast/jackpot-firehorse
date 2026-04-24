<?php

namespace App\Studio\Rendering;

/**
 * Turns {@see CompositionRenderPlan} strict-layer diagnostics into a short, user-facing sentence.
 */
final class StudioFfmpegNativeStrictLayerPolicyMessage
{
    /**
     * @param  array<string, mixed>  $layerDiagnostics
     */
    public static function summarize(array $layerDiagnostics): string
    {
        $bits = [];
        foreach ($layerDiagnostics['unsupported_visible'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (string) ($row['layer_id'] ?? '?');
            $type = (string) ($row['type'] ?? '?');
            $reason = (string) ($row['reason'] ?? 'unsupported');
            $bits[] = self::oneUnsupported($id, $type, $reason);
        }
        foreach ($layerDiagnostics['skipped_below_primary_video'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (string) ($row['layer_id'] ?? '?');
            $type = (string) ($row['type'] ?? '?');
            $z = $row['z'] ?? '?';
            $pz = $row['primary_video_z'] ?? '?';
            $bits[] = "Layer {$id} ({$type}): z-order {$z} is below the main video layer (z={$pz}) — raise this layer above the base clip, hide it, or use browser/canvas export.";
        }

        if ($bits === []) {
            return '';
        }

        return implode(' ', $bits);
    }

    private static function oneUnsupported(string $layerId, string $type, string $reason): string
    {
        $hint = match ($reason) {
            'unknown_or_unsupported_layer_type' => 'this layer type is not supported in FFmpeg-native V1 yet',
            'text_layer_empty_content' => 'text layer has no visible characters — add text or hide the layer',
            'fill_radial_or_unsupported_v1' => 'fill uses a scrim or fill shape that V1 cannot rasterize — use solid or linear gradient, or simplify the scrim',
            default => 'see reason code '.$reason.' in diagnostics',
        };

        return "Layer {$layerId} ({$type}): {$hint}.";
    }
}
