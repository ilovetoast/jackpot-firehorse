<?php

namespace App\Services\Editor;

/**
 * HTTP-shaped result for editor image edit (mirrors prior JsonResponse payloads + status codes).
 */
final class EditorGenerativeImageEditOutcome
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly int $status,
        public readonly array $data,
    ) {}
}
