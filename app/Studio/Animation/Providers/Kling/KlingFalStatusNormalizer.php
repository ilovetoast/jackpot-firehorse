<?php

namespace App\Studio\Animation\Providers\Kling;

final class KlingFalStatusNormalizer
{
    /**
     * @param  array<string, mixed>|null  $falStatusJson
     * @return array{
     *   normalized_provider_status: string,
     *   provider_queue_state: string|null,
     *   notes: string|null
     * }
     */
    public static function fromQueueStatus(?array $falStatusJson, bool $statusRequestFailed): array
    {
        if ($statusRequestFailed) {
            return [
                'normalized_provider_status' => 'provider_status_unknown',
                'provider_queue_state' => null,
                'notes' => 'status_http_failed',
            ];
        }

        $state = strtoupper((string) ($falStatusJson['status'] ?? ''));

        return match ($state) {
            'IN_QUEUE' => [
                'normalized_provider_status' => 'provider_queued',
                'provider_queue_state' => 'IN_QUEUE',
                'notes' => null,
            ],
            'IN_PROGRESS' => [
                'normalized_provider_status' => 'provider_processing',
                'provider_queue_state' => 'IN_PROGRESS',
                'notes' => null,
            ],
            'COMPLETED' => [
                'normalized_provider_status' => 'provider_complete',
                'provider_queue_state' => 'COMPLETED',
                'notes' => null,
            ],
            'FAILED' => [
                'normalized_provider_status' => 'provider_failed',
                'provider_queue_state' => 'FAILED',
                'notes' => null,
            ],
            default => [
                'normalized_provider_status' => 'provider_processing',
                'provider_queue_state' => $state !== '' ? $state : null,
                'notes' => 'unexpected_queue_state',
            ],
        };
    }
}
