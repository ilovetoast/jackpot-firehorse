<?php

namespace App\Services\Assets;

/**
 * Outcome of {@see AssetProcessingBudgetService::classify()} for worker-safe processing.
 */
final class ProcessingBudgetDecision
{
    public const ALLOWED = 'allowed';

    public const DEFER_TO_HEAVY_WORKER = 'defer_to_heavy_worker';

    public const FAIL_FILE_TOO_LARGE = 'fail_file_too_large';

    public const FAIL_PIXEL_LIMIT_EXCEEDED = 'fail_pixel_limit_exceeded';

    public const SKIP_UNSUPPORTED_ON_WORKER = 'skip_unsupported_on_worker';

    public function __construct(
        public readonly string $kind,
        public readonly ?string $failureCode,
        public readonly string $humanMessage,
        public readonly string $workerProfile,
        public readonly int $fileSizeBytes,
        public readonly ?int $configuredLimitBytes,
        public readonly ?string $mimeType,
    ) {}

    public static function allowed(string $workerProfile, int $fileSizeBytes, ?string $mimeType): self
    {
        return new self(
            self::ALLOWED,
            null,
            '',
            $workerProfile,
            $fileSizeBytes,
            null,
            $mimeType,
        );
    }

    public function isAllowed(): bool
    {
        return $this->kind === self::ALLOWED;
    }

    public function shouldDeferToHeavyWorker(): bool
    {
        return $this->kind === self::DEFER_TO_HEAVY_WORKER;
    }

    /**
     * True when this worker must not run decode/raster thumbnail work for the asset.
     */
    public function blocksHeavyThumbnailWork(): bool
    {
        return $this->kind !== self::ALLOWED;
    }

    public function failureCode(): ?string
    {
        return $this->failureCode;
    }

    public function humanMessage(): string
    {
        return $this->humanMessage;
    }

    public function configuredLimitBytes(): ?int
    {
        return $this->configuredLimitBytes;
    }
}
