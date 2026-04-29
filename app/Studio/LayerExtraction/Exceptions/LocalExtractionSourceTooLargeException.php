<?php

namespace App\Studio\LayerExtraction\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a source image exceeds {@see \App\Studio\LayerExtraction\Providers\FloodfillStudioLayerExtractionProvider} analysis limits.
 * AI segmentation may still work within SAM limits.
 */
final class LocalExtractionSourceTooLargeException extends InvalidArgumentException
{
    public const CODE = 'local_source_too_large';
}
