<?php

namespace Tests\Unit\Jobs;

use App\Enums\EventType;
use App\Models\ActivityEvent;
use App\Models\EventAggregate;
use App\Models\Tenant;
use App\Services\EventAggregationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🔒 STABILIZATION A1 — Aggregate Events Job Test
 * 
 * Tests idempotency and correctness of event aggregation.
 * Phase 4 Step 2 is LOCKED - tests only, no behavior changes.
 */
class AggregateEventsJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that running aggregation twice on the same events
     * does not double-count aggregates (idempotency).
     */
    public function test_aggregation_is_idempotent(): void
    {
        // Create test tenant
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-' . uniqid(),
        ]);

        // Create events in a time window
        $now = Carbon::now();
        $windowStart = $now->copy()->subMinutes(10);
        
        $eventType = EventType::DOWNLOAD_ZIP_FAILED;

        // Create 5 events
        for ($i = 0; $i < 5; $i++) {
            ActivityEvent::create([
                'tenant_id' => $tenant->id,
                'actor_type' => 'system',
                'event_type' => $eventType,
                'subject_type' => 'App\\Models\\Tenant',
                'subject_id' => (string) $tenant->id,
                'created_at' => $windowStart->copy()->addMinutes($i * 2),
            ]);
        }

        // Run aggregation first time
        $service = app(EventAggregationService::class);
        $service->aggregateTimeWindow($windowStart, $now);

        // Get aggregate count after first run
        $firstRunCount = EventAggregate::where('tenant_id', $tenant->id)
            ->where('event_type', $eventType)
            ->sum('count');

        $this->assertEquals(5, $firstRunCount, 'First aggregation should count all 5 events');

        // Run aggregation second time on same window
        $service->aggregateTimeWindow($windowStart, $now);

        // Get aggregate count after second run
        $secondRunCount = EventAggregate::where('tenant_id', $tenant->id)
            ->where('event_type', $eventType)
            ->sum('count');

        // Should still be 5, not 10 (no double-counting)
        $this->assertEquals(5, $secondRunCount, 'Second aggregation should not double-count events');
        $this->assertEquals($firstRunCount, $secondRunCount, 'Aggregation should be idempotent');
    }

    /**
     * Test that aggregates are created correctly for events in a time window.
     */
    public function test_aggregates_are_created_correctly(): void
    {
        // Create test tenant
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-' . uniqid(),
        ]);

        $now = Carbon::now();
        $windowStart = $now->copy()->subMinutes(10);
        $eventType = EventType::DOWNLOAD_ZIP_FAILED;

        // Create events
        ActivityEvent::create([
            'tenant_id' => $tenant->id,
            'actor_type' => 'system',
            'event_type' => $eventType,
            'subject_type' => 'App\\Models\\Tenant',
            'subject_id' => (string) $tenant->id,
            'created_at' => $windowStart->copy()->addMinutes(2),
        ]);

        ActivityEvent::create([
            'tenant_id' => $tenant->id,
            'actor_type' => 'system',
            'event_type' => $eventType,
            'subject_type' => 'App\\Models\\Tenant',
            'subject_id' => (string) $tenant->id,
            'created_at' => $windowStart->copy()->addMinutes(3),
        ]);

        // Run aggregation
        $service = app(EventAggregationService::class);
        $service->aggregateTimeWindow($windowStart, $now);

        // Verify aggregate was created
        $aggregate = EventAggregate::where('tenant_id', $tenant->id)
            ->where('event_type', $eventType)
            ->first();

        $this->assertNotNull($aggregate, 'Aggregate should be created');
        $this->assertEquals(2, $aggregate->count, 'Aggregate should count 2 events');
        $this->assertEquals($tenant->id, $aggregate->tenant_id);
        $this->assertEquals($eventType, $aggregate->event_type);
    }
}
