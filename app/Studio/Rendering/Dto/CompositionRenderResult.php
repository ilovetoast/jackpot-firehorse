<?php

namespace App\Studio\Rendering\Dto;

/**
 * Outcome of {@see \App\Studio\Rendering\Contracts\CompositionRenderer::render()}.
 *
 * @param  array<string, mixed>|null  $diagnostics
 */
final readonly class CompositionRenderResult
{
    /**
     * @param  array<string, mixed>|null  $diagnostics
     */
    public function __construct(
        public bool $ok,
        public ?string $localMp4Path,
        public ?string $failureCode,
        public ?string $failureMessage,
        public ?array $diagnostics,
    ) {}

    /**
     * @param  array<string, mixed>  $diagnostics
     */
    public static function success(string $localMp4Path, array $diagnostics = []): self
    {
        return new self(true, $localMp4Path, null, null, $diagnostics === [] ? null : $diagnostics);
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     */
    public static function failure(string $code, string $message, array $diagnostics = []): self
    {
        return new self(false, null, $code, $message, $diagnostics === [] ? null : $diagnostics);
    }
}
