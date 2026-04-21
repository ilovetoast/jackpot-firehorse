<?php

namespace App\Studio\Animation\Rendering;

final readonly class LockedDocumentServerFrameResult
{
    /**
     * @param  array<string, mixed>  $debug
     */
    private function __construct(
        public bool $ok,
        public ?string $pngBinary,
        public ?string $skipReason,
        public array $debug = [],
    ) {}

    /**
     * @param  array<string, mixed>  $debug
     */
    public static function success(string $pngBinary, array $debug = []): self
    {
        return new self(true, $pngBinary, null, $debug);
    }

    /**
     * @param  array<string, mixed>  $debug
     */
    public static function skipped(string $reason, array $debug = []): self
    {
        return new self(false, null, $reason, $debug);
    }
}
