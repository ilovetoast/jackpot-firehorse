<?php

namespace App\Studio\Animation\Enums;

enum StudioAnimationRenderRole: string
{
    case StartFrame = 'start_frame';
    case EndFrame = 'end_frame';
    case Reference = 'reference';
    case Mask = 'mask';
}
