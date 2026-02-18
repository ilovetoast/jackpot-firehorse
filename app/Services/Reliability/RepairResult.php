<?php

namespace App\Services\Reliability;

/**
 * Result of a repair strategy attempt.
 */
readonly class RepairResult
{
    public function __construct(
        public bool $resolved,
        public array $changes = [],
    ) {}
}
