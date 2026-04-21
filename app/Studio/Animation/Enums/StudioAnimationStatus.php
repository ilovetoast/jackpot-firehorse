<?php

namespace App\Studio\Animation\Enums;

enum StudioAnimationStatus: string
{
    case Queued = 'queued';
    case Rendering = 'rendering';
    case Submitting = 'submitting';
    case Processing = 'processing';
    case Downloading = 'downloading';
    case Finalizing = 'finalizing';
    case Complete = 'complete';
    case Failed = 'failed';
    case Canceled = 'canceled';
}
