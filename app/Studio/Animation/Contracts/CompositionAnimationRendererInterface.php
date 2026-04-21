<?php

namespace App\Studio\Animation\Contracts;

use App\Models\StudioAnimationJob;
use App\Studio\Animation\Data\StudioAnimationRenderData;

interface CompositionAnimationRendererInterface
{
    public function renderStartFrame(StudioAnimationJob $job): StudioAnimationRenderData;

    public function renderEndFrame(StudioAnimationJob $job): ?StudioAnimationRenderData;
}
