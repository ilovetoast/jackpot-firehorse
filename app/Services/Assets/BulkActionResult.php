<?php

namespace App\Services\Assets;

/**
 * Phase B1: Result of a bulk action execution.
 */
final class BulkActionResult
{
    public function __construct(
        public readonly int $totalSelected,
        public readonly int $processed,
        public readonly int $skipped,
        /** @var array<int, array{asset_id: string, reason: string}> */
        public readonly array $errors,
        /** @var array<string, mixed> Per-action summary (e.g. published_count, skipped_already_published) */
        public readonly array $perActionSummary = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'total_selected' => $this->totalSelected,
            'processed' => $this->processed,
            'skipped' => $this->skipped,
            'errors' => array_values($this->errors),
            'per_action_summary' => $this->perActionSummary,
        ];
    }
}
