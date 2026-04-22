<?php

namespace App\Studio\Animation\Enums;

enum StudioAnimationSourceStrategy: string
{
    case CompositionSnapshot = 'composition_snapshot';
    case GroupSnapshot = 'group_snapshot';
    case LayerSnapshot = 'layer_snapshot';
    case StartEndSnapshot = 'start_end_snapshot';

    /** Animate from a tight crop of the selected layer (requires layer_bounds in job settings). */
    case SelectedLayerIsolated = 'selected_layer_isolated';

    /** Same pixels as full composition; layer id stored for traceability and future masking. */
    case SelectedLayerWithContext = 'selected_layer_with_context';
}
