<?php

namespace App\Console\Commands;

use App\Jobs\AggregateEventsJob;
use App\Models\ActivityEvent;
use App\Models\AlertCandidate;
use App\Models\AlertSummary;
use App\Models\DetectionRule;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Services\AlertCandidateService;
use App\Services\AlertSummaryService;
use App\Services\AutoTicketCreationService;
use App\Services\EventAggregationService;
use App\Services\PatternDetectionService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Dev Generate Alert Command
 *
 * DEV-ONLY command to generate realistic data for testing the alert pipeline end-to-end.
 *
 * This command:
 * - Generates fake activity_events matching a DetectionRule
 * - Runs AggregateEventsJob to aggregate events
 * - Runs PatternDetectionService to detect patterns
 * - Creates AlertCandidates via AlertCandidateService
 * - Creates SupportTickets via AutoTicketCreationService
 * - Generates AlertSummaries via AlertSummaryService
 *
 * Only runs in 'local' or 'testing' environments.
 */
class DevGenerateAlertCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dev:generate-alert
                            {--tenant= : Tenant ID (required)}
                            {--rule= : Detection Rule ID (optional, picks first enabled rule if not provided)}
                            {--severity=critical : Severity level (critical|warning)}
                            {--count=5 : Number of events to generate}
                            {--window=15 : Time window in minutes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'DEV-ONLY: Generate alert pipeline data (events → aggregates → alerts → tickets → summaries)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Environment check - MUST be local or testing
        $environment = app()->environment();
        if (!in_array($environment, ['local', 'testing'])) {
            $this->error("❌ This command can only run in 'local' or 'testing' environments.");
            $this->error("   Current environment: {$environment}");
            return Command::FAILURE;
        }

        $this->info("🚀 Generating alert pipeline data...");
        $this->newLine();

        try {
            // Step 1: Validate and resolve inputs
            $tenant = $this->resolveTenant();
            $rule = $this->resolveRule();
            $severity = $this->option('severity');
            $count = (int) $this->option('count');
            $windowMinutes = (int) $this->option('window');

            $this->displayConfiguration($tenant, $rule, $severity, $count, $windowMinutes);

            // Step 2: Generate activity events
            $this->info("📝 Step 1: Generating activity events...");
            // Ensure we generate at least threshold_count + 1 events if comparison is 'greater_than'
            $minCount = $rule->comparison === 'greater_than' ? $rule->threshold_count + 1 : $rule->threshold_count;
            $actualCount = max($count, $minCount);
            if ($actualCount > $count) {
                $this->warn("   → Adjusted count from {$count} to {$actualCount} to meet threshold requirement");
            }
            $events = $this->generateActivityEvents($tenant, $rule, $actualCount, $windowMinutes);
            $this->info("   ✓ Created {$events->count()} events");

            // Step 3: Run aggregation
            $this->info("📊 Step 2: Running event aggregation...");
            // Use rule's window or provided window, whichever is larger, plus buffer
            $aggregationWindow = max($windowMinutes, $rule->threshold_window_minutes) + 5;
            $aggregationStats = $this->runAggregation($aggregationWindow);
            $this->info("   ✓ Aggregation complete");
            $this->line("   → Processed: {$aggregationStats['processed']} events");
            $this->line("   → Tenant aggregates created: {$aggregationStats['tenant_aggregates']}");
            $this->line("   → Asset aggregates created: {$aggregationStats['asset_aggregates']}");

            // Step 4: Run pattern detection
            $this->info("🔍 Step 3: Running pattern detection...");
            // Use the rule's threshold window for detection (should match our generated events)
            $detectionResults = $this->runPatternDetection();
            $this->info("   ✓ Pattern detection complete");

            // Step 4: Create alert candidates
            $this->info("⚠️  Step 4: Creating alert candidates...");
            if ($detectionResults->isEmpty()) {
                $this->warn("   ⚠ No detection results found");
                $this->warn("   → Check if aggregation created aggregates for this event type");
                $this->warn("   → Verify threshold is met (generated {$actualCount} events, rule threshold: {$rule->threshold_count})");
                $this->warn("   → Rule comparison: {$rule->comparison}");
                return Command::FAILURE;
            }
            $this->info("   → Found {$detectionResults->count()} detection result(s)");
            $alertCandidate = $this->createAlertCandidates($detectionResults, $rule);
            if ($alertCandidate) {
                $this->info("   ✓ Created AlertCandidate #{$alertCandidate->id}");
            } else {
                $this->warn("   ⚠ No alert candidate created (threshold not met or no matching results)");
                $this->line("   → Detection results:");
                foreach ($detectionResults as $result) {
                    $this->line("      - Rule: {$result['rule_name']}, Observed: {$result['observed_count']}, Threshold: {$result['threshold_count']}");
                }
                return Command::FAILURE;
            }

            // Step 6: Auto-create tickets (if rules allow)
            $this->info("🎫 Step 5: Auto-creating support tickets...");
            $ticket = $this->createTickets($alertCandidate);
            if ($ticket) {
                $this->info("   ✓ Created SupportTicket #{$ticket->id}");
            } else {
                $this->warn("   ⚠ No ticket created (rules did not trigger auto-creation)");
            }

            // Step 7: Generate AI summaries
            $this->info("🤖 Step 6: Generating AI summaries...");
            $summary = $this->generateSummary($alertCandidate);
            if ($summary) {
                $this->info("   ✓ Generated AlertSummary (AI: " . ($summary->confidence_score ? 'Yes' : 'Stub') . ")");
            } else {
                $this->warn("   ⚠ Summary generation failed");
            }

            // Final summary
            $this->newLine();
            $this->displaySummary($events->count(), $alertCandidate, $ticket, $summary);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("❌ Error: {$e->getMessage()}");
            $this->error($e->getTraceAsString());
            Log::error('Dev generate alert command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return Command::FAILURE;
        }
    }

    /**
     * Resolve tenant from option or prompt.
     */
    protected function resolveTenant(): Tenant
    {
        $tenantId = $this->option('tenant');
        
        if (!$tenantId) {
            $tenantId = $this->ask('Enter Tenant ID');
        }

        $tenant = Tenant::find($tenantId);
        
        if (!$tenant) {
            throw new \InvalidArgumentException("Tenant #{$tenantId} not found");
        }

        return $tenant;
    }

    /**
     * Resolve detection rule from option or use first enabled rule.
     */
    protected function resolveRule(): DetectionRule
    {
        $ruleId = $this->option('rule');
        
        if ($ruleId) {
            $rule = DetectionRule::find($ruleId);
            if (!$rule) {
                throw new \InvalidArgumentException("DetectionRule #{$ruleId} not found");
            }
            return $rule;
        }

        // Find first enabled rule, or any rule if none enabled
        $rule = DetectionRule::enabled()->first() ?? DetectionRule::first();
        
        if (!$rule) {
            throw new \InvalidArgumentException("No DetectionRule found. Please create a detection rule first.");
        }

        // Enable it if disabled for testing
        if (!$rule->enabled) {
            $this->warn("   ⚠ Rule '{$rule->name}' is disabled. Enabling for testing...");
            $rule->update(['enabled' => true]);
        }

        return $rule;
    }

    /**
     * Display configuration summary.
     */
    protected function displayConfiguration(Tenant $tenant, DetectionRule $rule, string $severity, int $count, int $windowMinutes): void
    {
        $this->info("📋 Configuration:");
        $this->table(
            ['Setting', 'Value'],
            [
                ['Tenant', "{$tenant->name} (ID: {$tenant->id})"],
                ['Detection Rule', "{$rule->name} (ID: {$rule->id})"],
                ['Event Type', $rule->event_type],
                ['Scope', $rule->scope],
                ['Threshold', "{$rule->threshold_count} in {$rule->threshold_window_minutes}min"],
                ['Severity', $severity],
                ['Events to Generate', $count],
                ['Time Window', "{$windowMinutes} minutes"],
            ]
        );
        $this->newLine();
    }

    /**
     * Generate activity events matching the detection rule.
     */
    protected function generateActivityEvents(Tenant $tenant, DetectionRule $rule, int $count, int $windowMinutes): \Illuminate\Support\Collection
    {
        $events = collect();
        $startTime = Carbon::now()->subMinutes($windowMinutes);
        $endTime = Carbon::now();

        // Distribute events across the time window
        for ($i = 0; $i < $count; $i++) {
            $eventTime = Carbon::createFromTimestamp(
                $startTime->timestamp + (($endTime->timestamp - $startTime->timestamp) / $count * $i)
            );

            // Build metadata based on rule's metadata_filters
            $metadata = $this->buildEventMetadata($rule);

            // Determine subject based on rule scope
            $subject = $this->determineSubject($tenant, $rule);

            // Create activity event
            $event = ActivityEvent::create([
                'tenant_id' => $tenant->id,
                'brand_id' => null, // Can be enhanced later
                'actor_type' => 'system',
                'actor_id' => null,
                'event_type' => $rule->event_type,
                'subject_type' => $subject['type'],
                'subject_id' => $subject['id'],
                'metadata' => array_merge($metadata, [
                    '_dev_generated' => true,
                    '_generated_at' => now()->toIso8601String(),
                ]),
                'ip_address' => '127.0.0.1',
                'user_agent' => 'DevTool/1.0',
                'created_at' => $eventTime,
            ]);

            $events->push($event);
        }

        return $events;
    }

    /**
     * Build event metadata based on rule's metadata_filters.
     */
    protected function buildEventMetadata(DetectionRule $rule): array
    {
        $metadata = [];

        if ($rule->metadata_filters) {
            // If rule has metadata_filters, include them in events
            foreach ($rule->metadata_filters as $key => $value) {
                if ($key === 'error_codes') {
                    // Special handling for error_codes (nested array)
                    $metadata['error_codes'] = [$value => 1];
                } else {
                    $metadata[$key] = $value;
                }
            }
        }

        return $metadata;
    }

    /**
     * Determine subject for event based on rule scope.
     */
    protected function determineSubject(Tenant $tenant, DetectionRule $rule): array
    {
        // For testing, create simple subject IDs based on scope
        switch ($rule->scope) {
            case 'tenant':
                return [
                    'type' => 'App\\Models\\Tenant',
                    'id' => (string) $tenant->id,
                ];
            case 'asset':
                // Use a fake asset ID (can be improved with actual asset lookup)
                return [
                    'type' => 'App\\Models\\Asset',
                    'id' => 'dev-test-asset-' . $tenant->id,
                ];
            case 'download':
                // Use a fake download ID
                return [
                    'type' => 'App\\Models\\Download',
                    'id' => 'dev-test-download-' . $tenant->id,
                ];
            case 'global':
            default:
                // Global scope - use tenant as subject
                return [
                    'type' => 'App\\Models\\Tenant',
                    'id' => (string) $tenant->id,
                ];
        }
    }

    /**
     * Run event aggregation.
     */
    protected function runAggregation(int $windowMinutes): array
    {
        $endAt = Carbon::now();
        $startAt = Carbon::now()->subMinutes($windowMinutes + 5); // Add buffer

        // Use service directly instead of job for synchronous execution
        $service = app(EventAggregationService::class);
        return $service->aggregateTimeWindow($startAt, $endAt);
    }

    /**
     * Run pattern detection.
     * 
     * Evaluates all enabled rules. Since we just generated events,
     * evaluation happens at "now" which should include our recent events.
     */
    protected function runPatternDetection(): \Illuminate\Support\Collection
    {
        $service = app(PatternDetectionService::class);
        // Evaluate at "now" - this will look back threshold_window_minutes from now
        // which should include our generated events
        return $service->evaluateAllRules(Carbon::now());
    }

    /**
     * Create alert candidates from detection results.
     */
    protected function createAlertCandidates(\Illuminate\Support\Collection $detectionResults, DetectionRule $rule): ?AlertCandidate
    {
        // Filter results for our rule
        $matchingResults = $detectionResults->filter(function ($result) use ($rule) {
            return $result['rule_id'] === $rule->id;
        });

        if ($matchingResults->isEmpty()) {
            return null;
        }

        $service = app(AlertCandidateService::class);
        $alertCandidate = null;

        foreach ($matchingResults as $result) {
            // createOrUpdateAlert expects: array $result, Carbon $detectedAt
            $alertCandidate = $service->createOrUpdateAlert($result, Carbon::now());
        }

        return $alertCandidate;
    }

    /**
     * Create tickets via auto-ticket creation service.
     */
    protected function createTickets(?AlertCandidate $alertCandidate): ?SupportTicket
    {
        if (!$alertCandidate) {
            return null;
        }

        $service = app(AutoTicketCreationService::class);
        $tickets = $service->evaluateAndCreateTickets(collect([$alertCandidate]));

        return $tickets->first();
    }

    /**
     * Generate AI summary for alert candidate.
     */
    protected function generateSummary(?AlertCandidate $alertCandidate): ?AlertSummary
    {
        if (!$alertCandidate) {
            return null;
        }

        try {
            $service = app(AlertSummaryService::class);
            return $service->generateSummary($alertCandidate);
        } catch (\Throwable $e) {
            Log::warning('[DevGenerateAlertCommand] Summary generation failed', [
                'alert_candidate_id' => $alertCandidate->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Display final summary.
     */
    protected function displaySummary(int $eventCount, ?AlertCandidate $alertCandidate, ?SupportTicket $ticket, ?AlertSummary $summary): void
    {
        $this->info("✅ Pipeline Complete!");
        $this->newLine();

        $this->table(
            ['Item', 'Status', 'Details'],
            [
                ['Events Generated', '✓', "{$eventCount} events"],
                ['Alert Candidate', $alertCandidate ? '✓' : '✗', $alertCandidate ? "ID: {$alertCandidate->id}, Status: {$alertCandidate->status}" : 'Not created'],
                ['Support Ticket', $ticket ? '✓' : '⚠', $ticket ? "ID: {$ticket->id}, Status: {$ticket->status}" : 'Not created (check ticket rules)'],
                ['Alert Summary', $summary ? '✓' : '✗', $summary ? ($summary->confidence_score ? "AI-generated (confidence: {$summary->confidence_score})" : 'Stub generated') : 'Not generated'],
            ]
        );

        if ($alertCandidate) {
            $this->newLine();
            $this->info("🔗 View alert in admin UI:");
            $this->line("   /app/admin/ai?tab=alerts");
        }
    }
}
