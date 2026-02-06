<?php

namespace App\Services\TenantBucket;

class EnsureBucketResult
{
    public const OUTCOME_CREATED = 'created';
    public const OUTCOME_SKIPPED = 'skipped';
    public const OUTCOME_FAILED = 'failed';

    public function __construct(
        public string $outcome,
        public string $bucketName,
        public ?string $errorMessage = null,
    ) {
    }

    public function wasCreated(): bool
    {
        return $this->outcome === self::OUTCOME_CREATED;
    }

    public function wasSkipped(): bool
    {
        return $this->outcome === self::OUTCOME_SKIPPED;
    }

    public function failed(): bool
    {
        return $this->outcome === self::OUTCOME_FAILED;
    }
}
