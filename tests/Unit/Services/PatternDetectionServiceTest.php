<?php

namespace Tests\Unit\Services;

use App\Enums\EventType;
use App\Models\DetectionRule;
use App\Models\EventAggregate;
use App\Models\Tenant;
use App\Services\PatternDetectionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🔒 STABILIZATION A1 — Pattern Detection Service Test
 * 
 * Tests pattern detection rule evaluation.
 * Phase 4 Step 3 is LOCKED - tests only, no behavior changes.
 */
class PatternDetectionServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that rule matches when aggregates exceed threshold.
     */
    public function test_rule_matches_when_threshold_exceeded(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-' . uniqid(),
        ]);
        $service = app(PatternDetectionService::class);

        // Create detection rule
        $rule = DetectionRule::create([
            'name' => 'Test Rule',
            'event_type' => EventType::DOWNLOAD_ZIP_FAILED,
            'scope' => 'tenant',
            'threshold_count' => 5,
            'threshold_window_minutes' => 15,
            'comparison' => 'greater_than_or_equal',
            'severity' => 'warning',
            'enabled' => true,
        ]);

        // Create aggregates above threshold
        $windowStart = Carbon::now()->subMinutes(10);
        
        EventAggregate::create([
            'tenant_id' => $tenant->id,
            'event_type' => EventType::DOWNLOAD_ZIP_FAILED,
            'bucket_start_at' => $windowStart,
            'bucket_end_at' => $windowStart->copy()->addMinutes(5),
            'count' => 6, // Above threshold of 5
            'success_count' => 0,
            'failure_count' => 6,
        ]);

        // Evaluate rules
        $results = $service->evaluateAllRules();

        // Should find a match
        $matchingResult = $results->firstWhere('rule_id', $rule->id);
        
        $this->assertNotNull($matchingResult, 'Rule should match when threshold is exceeded');
        $this->assertEquals($rule->id, $matchingResult['rule_id']);
        $this->assertEquals('tenant', $matchingResult['scope']);
        $this->assertEquals((string) $tenant->id, $matchingResult['subject_id']);
        $this->assertGreaterThanOrEqual($rule->threshold_count, $matchingResult['observed_count']);
    }

    /**
     * Test that rule does not match when threshold not met.
     */
    public function test_rule_does_not_match_below_threshold(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-' . uniqid(),
        ]);
        $service = app(PatternDetectionService::class);

        // Create detection rule
        $rule = DetectionRule::create([
            'name' => 'Test Rule',
            'event_type' => EventType::DOWNLOAD_ZIP_FAILED,
            'scope' => 'tenant',
            'threshold_count' => 5,
            'threshold_window_minutes' => 15,
            'comparison' => 'greater_than_or_equal',
            'severity' => 'warning',
            'enabled' => true,
        ]);

        // Create aggregates below threshold
        $windowStart = Carbon::now()->subMinutes(10);
        
        EventAggregate::create([
            'tenant_id' => $tenant->id,
            'event_type' => EventType::DOWNLOAD_ZIP_FAILED,
            'bucket_start_at' => $windowStart,
            'bucket_end_at' => $windowStart->copy()->addMinutes(5),
            'count' => 3, // Below threshold of 5
            'success_count' => 0,
            'failure_count' => 3,
        ]);

        // Evaluate rules
        $results = $service->evaluateAllRules();

        // Should not find a match
        $matchingResult = $results->firstWhere('rule_id', $rule->id);
        
        $this->assertNull($matchingResult, 'Rule should not match when threshold is not met');
    }

    /**
     * Test that metadata filters exclude non-matching aggregates.
     */
    public function test_metadata_filter_excludes_non_matching_aggregates(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-' . uniqid(),
        ]);
        $service = app(PatternDetectionService::class);

        // Create rule with metadata filter
        $rule = DetectionRule::create([
            'name' => 'Test Rule with Filter',
            'event_type' => EventType::ASSET_UPLOAD_FINALIZED,
            'scope' => 'tenant',
            'threshold_count' => 1,
            'threshold_window_minutes' => 15,
            'comparison' => 'greater_than_or_equal',
            'metadata_filters' => [
                'error_codes' => 'UPLOAD_VALIDATION_FAILED',
            ],
            'severity' => 'warning',
            'enabled' => true,
        ]);

        $windowStart = Carbon::now()->subMinutes(10);

        // Create aggregate WITHOUT matching metadata (should be excluded)
        EventAggregate::create([
            'tenant_id' => $tenant->id,
            'event_type' => EventType::ASSET_UPLOAD_FINALIZED,
            'bucket_start_at' => $windowStart,
            'bucket_end_at' => $windowStart->copy()->addMinutes(5),
            'count' => 10,
            'success_count' => 10,
            'failure_count' => 0,
            'metadata' => [], // No error_codes
        ]);

        // Evaluate rules
        $results = $service->evaluateAllRules();

        // Should not match (metadata filter excludes it)
        $matchingResult = $results->firstWhere('rule_id', $rule->id);
        $this->assertNull($matchingResult, 'Rule should not match when metadata filter excludes aggregates');

        // Create aggregate WITH matching metadata (should be included)
        EventAggregate::create([
            'tenant_id' => $tenant->id,
            'event_type' => EventType::ASSET_UPLOAD_FINALIZED,
            'bucket_start_at' => $windowStart->copy()->addMinutes(5),
            'bucket_end_at' => $windowStart->copy()->addMinutes(10),
            'count' => 2,
            'success_count' => 0,
            'failure_count' => 2,
            'metadata' => [
                'error_codes' => [
                    'UPLOAD_VALIDATION_FAILED' => 2,
                ],
            ],
        ]);

        // Evaluate again
        $results = $service->evaluateAllRules();
        $matchingResult = $results->firstWhere('rule_id', $rule->id);

        $this->assertNotNull($matchingResult, 'Rule should match when metadata filter matches');
        $this->assertEquals(2, $matchingResult['observed_count'], 'Should count only matching aggregates');
    }
}
