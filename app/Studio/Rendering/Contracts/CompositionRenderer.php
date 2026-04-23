<?php

namespace App\Studio\Rendering\Contracts;

use App\Studio\Rendering\Dto\CompositionRenderRequest;
use App\Studio\Rendering\Dto\CompositionRenderResult;

/**
 * Pluggable composition MP4 render engine (FFmpeg-native vs headless browser capture).
 */
interface CompositionRenderer
{
    public function render(CompositionRenderRequest $request): CompositionRenderResult;
}
