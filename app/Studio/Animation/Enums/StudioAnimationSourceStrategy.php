<?php

namespace App\Studio\Animation\Enums;

enum StudioAnimationSourceStrategy: string
{
    case CompositionSnapshot = 'composition_snapshot';
    case GroupSnapshot = 'group_snapshot';
    case LayerSnapshot = 'layer_snapshot';
    case StartEndSnapshot = 'start_end_snapshot';
}
