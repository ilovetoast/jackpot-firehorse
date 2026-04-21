<?php

namespace App\Studio\Animation\Data;

final readonly class ProviderAnimationResultData
{
    /**
     * @param  array<string, mixed>|null  $rawRequestDebug
     * @param  array<string, mixed>|null  $rawResponseDebug
     * @param  array<string, mixed>|null  $providerPhaseDebug
     */
    public function __construct(
        public string $phase,
        public ?string $providerJobId,
        public ?string $remoteVideoUrl,
        public ?int $remoteWidth,
        public ?int $remoteHeight,
        public ?int $remoteDurationSeconds,
        public ?string $errorCode,
        public ?string $errorMessage,
        public ?array $rawRequestDebug = null,
        public ?array $rawResponseDebug = null,
        public ?string $normalizedProviderStatus = null,
        public ?array $providerPhaseDebug = null,
    ) {}

    public function isTerminalSuccess(): bool
    {
        return $this->phase === 'complete' && $this->remoteVideoUrl !== null && $this->remoteVideoUrl !== '';
    }

    public function isTerminalFailure(): bool
    {
        return $this->phase === 'failed';
    }

    public function isInFlight(): bool
    {
        return in_array($this->phase, ['submitted', 'processing', 'queued'], true);
    }
}
