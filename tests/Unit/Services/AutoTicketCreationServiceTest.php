<?php

namespace Tests\Unit\Services;

use App\Models\AlertCandidate;
use App\Models\DetectionRule;
use App\Models\SupportTicket;
use App\Models\TicketCreationRule;
use App\Services\AutoTicketCreationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🔒 STABILIZATION A1 — Auto Ticket Creation Service Test
 * 
 * Tests automatic ticket creation from alerts.
 * Phase 5A Step 2 is LOCKED - tests only, no behavior changes.
 */
class AutoTicketCreationServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that exactly one ticket is created when rule matches.
     */
    public function test_creates_exactly_one_ticket_when_rule_matches(): void
    {
        // Create detection rule
        $detectionRule = DetectionRule::create([
            'name' => 'Test Rule',
            'event_type' => \App\Enums\EventType::DOWNLOAD_ZIP_FAILED,
            'scope' => 'tenant',
            'threshold_count' => 5,
            'threshold_window_minutes' => 15,
            'comparison' => 'greater_than_or_equal',
            'severity' => 'critical',
            'enabled' => true,
        ]);

        // Create ticket creation rule (enabled, critical severity, requires 1 detection)
        $ticketRule = TicketCreationRule::create([
            'rule_id' => $detectionRule->id,
            'min_severity' => 'critical',
            'required_detection_count' => 1,
            'auto_create' => true,
            'enabled' => true,
        ]);

        // Create alert candidate matching the rule
        $alert = AlertCandidate::create([
            'rule_id' => $detectionRule->id,
            'scope' => 'tenant',
            'subject_id' => '1',
            'tenant_id' => 1,
            'severity' => 'critical',
            'observed_count' => 5,
            'threshold_count' => 3,
            'window_minutes' => 15,
            'status' => 'open',
            'detection_count' => 1,
            'first_detected_at' => Carbon::now(),
            'last_detected_at' => Carbon::now(),
        ]);

        // Run auto-ticket creation
        $service = app(AutoTicketCreationService::class);
        $tickets = $service->evaluateAndCreateTickets(collect([$alert]));

        // Should create exactly one ticket
        $this->assertCount(1, $tickets, 'Should create exactly one ticket');
        
        $ticket = $tickets->first();
        $this->assertInstanceOf(SupportTicket::class, $ticket);
        $this->assertEquals($alert->id, $ticket->alert_candidate_id);
        $this->assertEquals('system', $ticket->source);
        $this->assertEquals('critical', $ticket->severity);
    }

    /**
     * Test that re-running does not create duplicate tickets (idempotency).
     */
    public function test_does_not_create_duplicate_tickets_on_rerun(): void
    {
        // Create detection rule
        $detectionRule = DetectionRule::create([
            'name' => 'Test Rule',
            'event_type' => \App\Enums\EventType::DOWNLOAD_ZIP_FAILED,
            'scope' => 'tenant',
            'threshold_count' => 5,
            'threshold_window_minutes' => 15,
            'comparison' => 'greater_than_or_equal',
            'severity' => 'critical',
            'enabled' => true,
        ]);

        // Create ticket creation rule
        $ticketRule = TicketCreationRule::create([
            'rule_id' => $detectionRule->id,
            'min_severity' => 'critical',
            'required_detection_count' => 1,
            'auto_create' => true,
            'enabled' => true,
        ]);

        // Create alert candidate
        $alert = AlertCandidate::create([
            'rule_id' => $detectionRule->id,
            'scope' => 'tenant',
            'subject_id' => '1',
            'tenant_id' => 1,
            'severity' => 'critical',
            'observed_count' => 5,
            'threshold_count' => 3,
            'window_minutes' => 15,
            'status' => 'open',
            'detection_count' => 1,
            'first_detected_at' => Carbon::now(),
            'last_detected_at' => Carbon::now(),
        ]);

        $service = app(AutoTicketCreationService::class);

        // Run first time
        $firstRun = $service->evaluateAndCreateTickets(collect([$alert]));
        $this->assertCount(1, $firstRun, 'First run should create one ticket');

        $initialTicketId = $firstRun->first()->id;

        // Run second time
        $secondRun = $service->evaluateAndCreateTickets(collect([$alert]));
        $this->assertCount(0, $secondRun, 'Second run should not create duplicate ticket');

        // Verify only one ticket exists
        $ticketCount = SupportTicket::where('alert_candidate_id', $alert->id)->count();
        $this->assertEquals(1, $ticketCount, 'Should still have exactly one ticket');
        
        // Verify it's the same ticket
        $ticket = SupportTicket::where('alert_candidate_id', $alert->id)->first();
        $this->assertEquals($initialTicketId, $ticket->id, 'Should be the same ticket');
    }

    /**
     * Test that tickets are not created when rule requirements not met.
     */
    public function test_does_not_create_ticket_when_requirements_not_met(): void
    {
        // Create detection rule
        $detectionRule = DetectionRule::create([
            'name' => 'Test Rule',
            'event_type' => \App\Enums\EventType::DOWNLOAD_ZIP_FAILED,
            'scope' => 'tenant',
            'threshold_count' => 5,
            'threshold_window_minutes' => 15,
            'comparison' => 'greater_than_or_equal',
            'severity' => 'critical',
            'enabled' => true,
        ]);

        // Create ticket creation rule requiring 3 detections
        $ticketRule = TicketCreationRule::create([
            'rule_id' => $detectionRule->id,
            'min_severity' => 'critical',
            'required_detection_count' => 3,
            'auto_create' => true,
            'enabled' => true,
        ]);

        // Create alert with only 1 detection (below requirement)
        $alert = AlertCandidate::create([
            'rule_id' => $detectionRule->id,
            'scope' => 'tenant',
            'subject_id' => '1',
            'tenant_id' => 1,
            'severity' => 'critical',
            'observed_count' => 5,
            'threshold_count' => 3,
            'window_minutes' => 15,
            'status' => 'open',
            'detection_count' => 1, // Below required 3
            'first_detected_at' => Carbon::now(),
            'last_detected_at' => Carbon::now(),
        ]);

        // Run auto-ticket creation
        $service = app(AutoTicketCreationService::class);
        $tickets = $service->evaluateAndCreateTickets(collect([$alert]));

        // Should not create ticket
        $this->assertCount(0, $tickets, 'Should not create ticket when requirements not met');
        $this->assertEquals(0, SupportTicket::where('alert_candidate_id', $alert->id)->count());
    }
}
