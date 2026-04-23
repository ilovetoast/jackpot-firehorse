<?php

namespace App\Studio\Rendering;

use App\Studio\Rendering\Dto\CompositionRenderRequest;

/**
 * Validates normalized render requests before FFmpeg or browser dispatch.
 */
final class CompositionRenderValidator
{
    /**
     * @return list<string>
     */
    public function validate(CompositionRenderRequest $request): array
    {
        $errors = [];
        $t = $request->timeline;
        if ($t === null) {
            return ['missing_timeline'];
        }
        $maxW = max(2, (int) config('studio_rendering.max_canvas_width', 4096));
        $maxH = max(2, (int) config('studio_rendering.max_canvas_height', 4096));
        if ($t->width > $maxW || $t->height > $maxH) {
            $errors[] = 'canvas_dimensions_exceed_max';
        }
        $maxDur = (float) config('studio_rendering.max_output_duration_seconds', 7200);
        if ($t->outputDurationSeconds() > $maxDur) {
            $errors[] = 'output_duration_exceeds_max';
        }
        foreach ($request->layers as $ly) {
            if ($ly->mediaPath === null || $ly->mediaPath === '' || ! is_file($ly->mediaPath)) {
                $errors[] = 'missing_staged_media:'.$ly->id;
            }
        }

        return $errors;
    }
}
