<?php

namespace App\Services;

use App\Models\ActivityEvent;
use App\Models\AssetEventAggregate;
use App\Models\DownloadEventAggregate;
use App\Models\EventAggregate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 🔒 Phase 4 Step 2 — Event Aggregation Service
 * 
 * Consumes events from locked phases only.
 * Must not modify event producers.
 * 
 * EventAggregationService
 * 
 * Aggregates raw activity events into time-bucketed aggregates.
 * Handles tenant-level, asset-level, and download-level aggregations.
 */
class EventAggregationService
{
    /**
     * Default time bucket size in minutes.
     * Configurable via config or constant.
     */
    public const BUCKET_SIZE_MINUTES = 5;

    /**
     * Process events chunk size for batch processing.
     */
    public const CHUNK_SIZE = 1000;

    /**
     * Aggregate events for a specific time window.
     * 
     * @param Carbon $startAt Start of time window (inclusive)
     * @param Carbon $endAt End of time window (inclusive)
     * @return array{
     *   processed: int,
     *   tenant_aggregates: int,
     *   asset_aggregates: int,
     *   download_aggregates: int,
     *   errors: int
     * }
     */
    public function aggregateTimeWindow(Carbon $startAt, Carbon $endAt): array
    {
        Log::info('[EventAggregationService] Starting aggregation', [
            'start_at' => $startAt->toIso8601String(),
            'end_at' => $endAt->toIso8601String(),
            'bucket_size_minutes' => self::BUCKET_SIZE_MINUTES,
        ]);

        $stats = [
            'processed' => 0,
            'tenant_aggregates' => 0,
            'asset_aggregates' => 0,
            'download_aggregates' => 0,
            'errors' => 0,
        ];

        // Process events in chunks
        ActivityEvent::whereBetween('created_at', [$startAt, $endAt])
            ->orderBy('created_at')
            ->chunk(self::CHUNK_SIZE, function ($events) use ($startAt, $endAt, &$stats) {
                try {
                    $this->processEventsChunk($events, $startAt, $endAt, $stats);
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    Log::error('[EventAggregationService] Error processing chunk', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    // Continue with next chunk - don't stop aggregation
                }
            });

        Log::info('[EventAggregationService] Aggregation completed', $stats);

        return $stats;
    }

    /**
     * Process a chunk of events and update aggregates.
     * 
     * @param \Illuminate\Database\Eloquent\Collection $events
     * @param Carbon $windowStart
     * @param Carbon $windowEnd
     * @param array &$stats Stats array to update
     * @return void
     */
    protected function processEventsChunk($events, Carbon $windowStart, Carbon $windowEnd, array &$stats): void
    {
        // Group events by aggregation keys
        $tenantGroups = [];
        $assetGroups = [];
        $downloadGroups = [];

        foreach ($events as $event) {
            $stats['processed']++;

            // Calculate bucket for this event
            $bucketStart = $this->calculateBucketStart($event->created_at);
            $bucketEnd = $bucketStart->copy()->addMinutes(self::BUCKET_SIZE_MINUTES)->subSecond();

            // Group key for tenant-level aggregation
            $tenantKey = "{$event->tenant_id}|{$event->event_type}|{$bucketStart->toIso8601String()}";
            if (!isset($tenantGroups[$tenantKey])) {
                $tenantGroups[$tenantKey] = [
                    'tenant_id' => $event->tenant_id,
                    'brand_id' => $event->brand_id,
                    'event_type' => $event->event_type,
                    'bucket_start_at' => $bucketStart,
                    'bucket_end_at' => $bucketEnd,
                    'events' => [],
                ];
            }
            $tenantGroups[$tenantKey]['events'][] = $event;

            // Group for asset-level aggregation (if subject is Asset)
            if ($event->subject_type === \App\Models\Asset::class || $event->subject_type === 'Asset') {
                $assetKey = "{$event->subject_id}|{$event->event_type}|{$bucketStart->toIso8601String()}";
                if (!isset($assetGroups[$assetKey])) {
                    $assetGroups[$assetKey] = [
                        'tenant_id' => $event->tenant_id,
                        'asset_id' => $event->subject_id,
                        'event_type' => $event->event_type,
                        'bucket_start_at' => $bucketStart,
                        'bucket_end_at' => $bucketEnd,
                        'events' => [],
                    ];
                }
                $assetGroups[$assetKey]['events'][] = $event;
            }

            // Group for download-level aggregation (if subject is Download)
            if ($event->subject_type === \App\Models\Download::class || $event->subject_type === 'Download') {
                $downloadKey = "{$event->subject_id}|{$event->event_type}|{$bucketStart->toIso8601String()}";
                if (!isset($downloadGroups[$downloadKey])) {
                    $downloadGroups[$downloadKey] = [
                        'tenant_id' => $event->tenant_id,
                        'download_id' => $event->subject_id,
                        'event_type' => $event->event_type,
                        'bucket_start_at' => $bucketStart,
                        'bucket_end_at' => $bucketEnd,
                        'events' => [],
                    ];
                }
                $downloadGroups[$downloadKey]['events'][] = $event;
            }
        }

        // Update aggregates in transaction
        DB::transaction(function () use ($tenantGroups, $assetGroups, $downloadGroups, &$stats) {
            // Process tenant-level aggregates
            foreach ($tenantGroups as $group) {
                $this->upsertTenantAggregate($group);
                $stats['tenant_aggregates']++;
            }

            // Process asset-level aggregates
            foreach ($assetGroups as $group) {
                $this->upsertAssetAggregate($group);
                $stats['asset_aggregates']++;
            }

            // Process download-level aggregates
            foreach ($downloadGroups as $group) {
                $this->upsertDownloadAggregate($group);
                $stats['download_aggregates']++;
            }
        });
    }

    /**
     * Upsert tenant-level event aggregate.
     * 
     * @param array $group Group data with events
     * @return void
     */
    protected function upsertTenantAggregate(array $group): void
    {
        $events = $group['events'];
        $count = count($events);

        // Calculate success/failure counts
        $successCount = 0;
        $failureCount = 0;
        foreach ($events as $event) {
            $metadata = $event->metadata ?? [];
            if (!empty($metadata['error']) || !empty($metadata['error_code'])) {
                $failureCount++;
            } else {
                $successCount++;
            }
        }

        // Extract and aggregate metadata
        $aggregatedMetadata = $this->extractMetadata($events);

        // Get existing aggregate for metadata merge
        $existing = EventAggregate::where('tenant_id', $group['tenant_id'])
            ->where('event_type', $group['event_type'])
            ->where('bucket_start_at', $group['bucket_start_at'])
            ->first();

        // Merge metadata
        $existingMetadata = $existing ? $existing->metadata : null;
        $mergedMetadata = $this->mergeMetadata($existingMetadata, $aggregatedMetadata);

        // Calculate new counts (increment if exists, set if new)
        $newCount = $existing ? $existing->count + $count : $count;
        $newSuccessCount = $existing ? $existing->success_count + $successCount : $successCount;
        $newFailureCount = $existing ? $existing->failure_count + $failureCount : $failureCount;

        // Upsert aggregate record
        EventAggregate::updateOrCreate(
            [
                'tenant_id' => $group['tenant_id'],
                'event_type' => $group['event_type'],
                'bucket_start_at' => $group['bucket_start_at'],
            ],
            [
                'brand_id' => $group['brand_id'],
                'bucket_end_at' => $group['bucket_end_at'],
                'count' => $newCount,
                'success_count' => $newSuccessCount,
                'failure_count' => $newFailureCount,
                'metadata' => $mergedMetadata,
            ]
        );
    }

    /**
     * Upsert asset-level event aggregate.
     * 
     * @param array $group Group data with events
     * @return void
     */
    protected function upsertAssetAggregate(array $group): void
    {
        $events = $group['events'];
        $count = count($events);

        // Extract and aggregate metadata
        $aggregatedMetadata = $this->extractMetadata($events);

        // Get existing aggregate for metadata merge
        $existing = AssetEventAggregate::where('asset_id', $group['asset_id'])
            ->where('event_type', $group['event_type'])
            ->where('bucket_start_at', $group['bucket_start_at'])
            ->first();

        // Merge metadata
        $existingMetadata = $existing ? $existing->metadata : null;
        $mergedMetadata = $this->mergeMetadata($existingMetadata, $aggregatedMetadata);

        // Calculate new count (increment if exists, set if new)
        $newCount = $existing ? $existing->count + $count : $count;

        // Upsert aggregate record
        AssetEventAggregate::updateOrCreate(
            [
                'asset_id' => $group['asset_id'],
                'event_type' => $group['event_type'],
                'bucket_start_at' => $group['bucket_start_at'],
            ],
            [
                'tenant_id' => $group['tenant_id'],
                'bucket_end_at' => $group['bucket_end_at'],
                'count' => $newCount,
                'metadata' => $mergedMetadata,
            ]
        );
    }

    /**
     * Upsert download-level event aggregate.
     * 
     * @param array $group Group data with events
     * @return void
     */
    protected function upsertDownloadAggregate(array $group): void
    {
        $events = $group['events'];
        $count = count($events);

        // Extract and aggregate metadata
        $aggregatedMetadata = $this->extractMetadata($events);

        // Get existing aggregate for metadata merge
        $existing = DownloadEventAggregate::where('download_id', $group['download_id'])
            ->where('event_type', $group['event_type'])
            ->where('bucket_start_at', $group['bucket_start_at'])
            ->first();

        // Merge metadata
        $existingMetadata = $existing ? $existing->metadata : null;
        $mergedMetadata = $this->mergeMetadata($existingMetadata, $aggregatedMetadata);

        // Calculate new count (increment if exists, set if new)
        $newCount = $existing ? $existing->count + $count : $count;

        // Upsert aggregate record
        DownloadEventAggregate::updateOrCreate(
            [
                'download_id' => $group['download_id'],
                'event_type' => $group['event_type'],
                'bucket_start_at' => $group['bucket_start_at'],
            ],
            [
                'tenant_id' => $group['tenant_id'],
                'bucket_end_at' => $group['bucket_end_at'],
                'count' => $newCount,
                'metadata' => $mergedMetadata,
            ]
        );
    }

    /**
     * Calculate bucket start time for a given timestamp.
     * 
     * Buckets are aligned to clock time (e.g., 5-minute buckets: 00:00, 00:05, 00:10, ...)
     * 
     * @param Carbon $timestamp
     * @return Carbon
     */
    protected function calculateBucketStart(Carbon $timestamp): Carbon
    {
        $minutes = $timestamp->minute;
        $bucketMinute = floor($minutes / self::BUCKET_SIZE_MINUTES) * self::BUCKET_SIZE_MINUTES;
        
        return $timestamp->copy()
            ->startOfHour()
            ->addMinutes($bucketMinute);
    }

    /**
     * Extract metadata from events and aggregate counts.
     * 
     * @param \Illuminate\Database\Eloquent\Collection $events
     * @return array
     */
    protected function extractMetadata($events): array
    {
        $metadata = [
            'error_codes' => [],
            'file_types' => [],
            'contexts' => [],
            'download_types' => [],
            'sources' => [],
            'access_modes' => [],
        ];

        foreach ($events as $event) {
            $eventMetadata = $event->metadata ?? [];

            // Count error codes
            if (isset($eventMetadata['error_code'])) {
                $errorCode = $eventMetadata['error_code'];
                $metadata['error_codes'][$errorCode] = ($metadata['error_codes'][$errorCode] ?? 0) + 1;
            }

            // Count file types
            if (isset($eventMetadata['file_type'])) {
                $fileType = $eventMetadata['file_type'];
                $metadata['file_types'][$fileType] = ($metadata['file_types'][$fileType] ?? 0) + 1;
            }

            // Count contexts (zip vs single)
            if (isset($eventMetadata['context'])) {
                $context = $eventMetadata['context'];
                $metadata['contexts'][$context] = ($metadata['contexts'][$context] ?? 0) + 1;
            }

            // Count download types
            if (isset($eventMetadata['download_type'])) {
                $downloadType = $eventMetadata['download_type'];
                $metadata['download_types'][$downloadType] = ($metadata['download_types'][$downloadType] ?? 0) + 1;
            }

            // Count sources
            if (isset($eventMetadata['source'])) {
                $source = $eventMetadata['source'];
                $metadata['sources'][$source] = ($metadata['sources'][$source] ?? 0) + 1;
            }

            // Count access modes
            if (isset($eventMetadata['access_mode'])) {
                $accessMode = $eventMetadata['access_mode'];
                $metadata['access_modes'][$accessMode] = ($metadata['access_modes'][$accessMode] ?? 0) + 1;
            }
        }

        // Remove empty arrays
        return array_filter($metadata, fn($value) => !empty($value));
    }

    /**
     * Merge new metadata with existing metadata.
     * 
     * Combines counts from both sources.
     * 
     * @param array|null $existingMetadata
     * @param array $newMetadata
     * @return array
     */
    protected function mergeMetadata(?array $existingMetadata, array $newMetadata): array
    {
        if (empty($existingMetadata)) {
            return $newMetadata;
        }

        $merged = $existingMetadata;

        foreach ($newMetadata as $key => $value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                // Merge counts
                foreach ($value as $subKey => $subValue) {
                    $merged[$key][$subKey] = ($merged[$key][$subKey] ?? 0) + $subValue;
                }
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }
}
