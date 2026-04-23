<?php

namespace App\Studio\Rendering\Exceptions;

/**
 * Failed to resolve or stage a font for FFmpeg-native text rasterization.
 */
final class StudioFontResolutionException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
