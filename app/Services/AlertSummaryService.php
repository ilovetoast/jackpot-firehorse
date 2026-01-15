<?php

namespace App\Services;

use App\Enums\AITaskType;
use App\Models\AlertCandidate;
use App\Models\AlertSummary;
use App\Models\AssetEventAggregate;
use App\Models\DownloadEventAggregate;
use App\Models\EventAggregate;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * 🔒 Phase 4 Step 5 — Alert Summary Service
 * 
 * Consumes alert candidates from locked phases only.
 * Must not modify alert candidate lifecycle, detection rules, or aggregation logic.
 * 
 * AlertSummaryService
 * 
 * Generates human-readable AI summaries for alert candidates that explain:
 * - What is happening
 * - Who is affected
 * - When it started
 * - How severe it is
 * - Suggested next steps (non-binding)
 * 
 * AI CALL BEHAVIOR:
 * - Uses AIService if available
 * - Falls back to stub summary if AI call fails
 * - Must not block core flows
 * - Retry-safe (can regenerate summaries)
 * 
 * NO ACTIONS — summaries are for human consumption only.
 */
class AlertSummaryService
{
    /**
     * AI agent ID to use for alert summaries (must exist in config/ai.php)
     * Falls back to stub if not configured or AI call fails.
     */
    public const AI_AGENT_ID = 'alert_summarizer';

    /**
     * Threshold for regenerating summaries (detection_count increase).
     * Regenerate if detection_count increases by this factor.
     */
    public const REGENERATION_THRESHOLD_MULTIPLIER = 1.5;

    /**
     * Generate or update summary for an alert candidate.
     * 
     * @param AlertCandidate $alertCandidate
     * @param bool $forceRegenerate Force regeneration even if summary exists
     * @return AlertSummary
     */
    public function generateSummary(AlertCandidate $alertCandidate, bool $forceRegenerate = false): AlertSummary
    {
        // Check if summary already exists
        $existing = AlertSummary::where('alert_candidate_id', $alertCandidate->id)->first();

        if ($existing && !$forceRegenerate) {
            // Check if regeneration is needed
            if ($this->shouldRegenerateSummary($alertCandidate, $existing)) {
                Log::info('[AlertSummaryService] Regenerating summary due to significant changes', [
                    'alert_candidate_id' => $alertCandidate->id,
                    'detection_count' => $alertCandidate->detection_count,
                ]);
            } else {
                // Return existing summary
                return $existing;
            }
        }

        try {
            // Build prompt from alert candidate data
            $prompt = $this->buildPrompt($alertCandidate);

            // Attempt AI generation
            $aiService = app(AIService::class);
            $result = $aiService->executeAgent(
                self::AI_AGENT_ID,
                AITaskType::ALERT_SUMMARY,
                $prompt,
                [
                    'triggering_context' => 'system',
                    'tenant_id' => $alertCandidate->tenant_id,
                ]
            );

            // Parse AI response into structured summary
            $summary = $this->parseAIResponse($result['text'], $alertCandidate);

            Log::info('[AlertSummaryService] AI summary generated successfully', [
                'alert_candidate_id' => $alertCandidate->id,
                'agent_run_id' => $result['agent_run_id'] ?? null,
            ]);

        } catch (\Throwable $e) {
            // Fall back to stub summary if AI call fails
            Log::warning('[AlertSummaryService] AI call failed, using stub summary', [
                'alert_candidate_id' => $alertCandidate->id,
                'error' => $e->getMessage(),
            ]);

            $summary = $this->generateStubSummary($alertCandidate);
        }

        // Create or update summary
        if ($existing) {
            $existing->update($summary);
            return $existing->fresh();
        } else {
            return AlertSummary::create($summary);
        }
    }

    /**
     * Check if summary should be regenerated.
     * 
     * @param AlertCandidate $alertCandidate
     * @param AlertSummary $existingSummary
     * @return bool
     */
    protected function shouldRegenerateSummary(AlertCandidate $alertCandidate, AlertSummary $existingSummary): bool
    {
        // Regenerate if detection_count increased significantly
        // Compare with detection_count when summary was generated
        // Since we don't store original detection_count, regenerate if current count is much higher
        // This is a heuristic - can be improved in future phases

        // Regenerate if severity changed
        if ($existingSummary->severity !== $alertCandidate->severity) {
            return true;
        }

        // Regenerate if detection_count increased by threshold
        // (Simple heuristic: if current count is 1.5x+ of when summary was created, regenerate)
        // Since we don't track original count, we'll regenerate if detection_count >= 3 and summary is old
        if ($alertCandidate->detection_count >= 3) {
            $hoursSinceGeneration = $existingSummary->generated_at->diffInHours(now());
            // Regenerate if summary is older than 24 hours and detection_count is high
            if ($hoursSinceGeneration > 24) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build prompt for AI summary generation.
     * 
     * Prompt Structure:
     * 1. Alert description (rule name and description)
     * 2. Detection pattern (observed_count vs threshold, time window)
     * 3. Frequency (detection_count, first_detected_at, last_detected_at)
     * 4. Affected entities (scope, subject_id, tenant_id)
     * 5. Historical context (from aggregates if available)
     * 
     * @param AlertCandidate $alertCandidate
     * @return string
     */
    protected function buildPrompt(AlertCandidate $alertCandidate): string
    {
        $rule = $alertCandidate->rule;
        $context = $alertCandidate->context ?? [];

        // Get additional context from aggregates if available
        $aggregateContext = $this->getAggregateContext($alertCandidate);

        $prompt = "Generate a concise, human-readable summary for the following system alert:\n\n";
        
        // Alert description
        $prompt .= "## Alert Description\n";
        $prompt .= "Rule: {$rule->name}\n";
        if ($rule->description) {
            $prompt .= "Description: {$rule->description}\n";
        }
        $prompt .= "Event Type: {$rule->event_type}\n";
        $prompt .= "Scope: {$alertCandidate->scope}\n\n";

        // Detection pattern
        $prompt .= "## Detection Pattern\n";
        $prompt .= "Observed Count: {$alertCandidate->observed_count}\n";
        $prompt .= "Threshold: {$alertCandidate->threshold_count}\n";
        $prompt .= "Time Window: {$alertCandidate->window_minutes} minutes\n";
        $prompt .= "Severity: {$alertCandidate->severity}\n\n";

        // Frequency
        $prompt .= "## Frequency\n";
        $prompt .= "Detection Count: {$alertCandidate->detection_count}\n";
        $prompt .= "First Detected: {$alertCandidate->first_detected_at->toIso8601String()}\n";
        $prompt .= "Last Detected: {$alertCandidate->last_detected_at->toIso8601String()}\n\n";

        // Affected entities
        $prompt .= "## Affected Entities\n";
        $prompt .= "Scope: {$alertCandidate->scope}\n";
        if ($alertCandidate->subject_id) {
            $prompt .= "Subject ID: {$alertCandidate->subject_id}\n";
        }
        if ($alertCandidate->tenant_id) {
            $prompt .= "Tenant ID: {$alertCandidate->tenant_id}\n";
        }
        $prompt .= "\n";

        // Context metadata
        if (!empty($context)) {
            $prompt .= "## Context Metadata\n";
            $prompt .= json_encode($context, JSON_PRETTY_PRINT) . "\n\n";
        }

        // Aggregate context
        if (!empty($aggregateContext)) {
            $prompt .= "## Historical Context\n";
            $prompt .= $aggregateContext . "\n\n";
        }

        // Instructions
        $prompt .= "## Instructions\n";
        $prompt .= "Generate a structured summary with:\n";
        $prompt .= "1. Summary Text: A concise explanation of what is happening (2-3 sentences)\n";
        $prompt .= "2. Impact Summary: Who or what is affected (1-2 sentences)\n";
        $prompt .= "3. Affected Scope: Specific entity description (e.g., 'Tenant ABC', 'Asset XYZ')\n";
        $prompt .= "4. Suggested Actions: 2-3 actionable next steps as a JSON array\n";
        $prompt .= "\n";
        $prompt .= "Format your response as JSON:\n";
        $prompt .= "{\n";
        $prompt .= "  \"summary_text\": \"...\",\n";
        $prompt .= "  \"impact_summary\": \"...\",\n";
        $prompt .= "  \"affected_scope\": \"...\",\n";
        $prompt .= "  \"suggested_actions\": [\"action1\", \"action2\", \"action3\"]\n";
        $prompt .= "}\n";

        return $prompt;
    }

    /**
     * Get additional context from aggregates for the alert.
     * 
     * @param AlertCandidate $alertCandidate
     * @return string
     */
    protected function getAggregateContext(AlertCandidate $alertCandidate): string
    {
        $context = [];
        $rule = $alertCandidate->rule;

        // Get recent aggregates for this event type
        $windowStart = $alertCandidate->first_detected_at->copy()->subHours(1);

        switch ($alertCandidate->scope) {
            case 'global':
            case 'tenant':
                $aggregates = EventAggregate::where('event_type', $rule->event_type)
                    ->where('bucket_start_at', '>=', $windowStart)
                    ->orderBy('bucket_start_at', 'desc')
                    ->limit(10)
                    ->get();
                break;

            case 'asset':
                if ($alertCandidate->subject_id) {
                    $aggregates = AssetEventAggregate::where('event_type', $rule->event_type)
                        ->where('asset_id', $alertCandidate->subject_id)
                        ->where('bucket_start_at', '>=', $windowStart)
                        ->orderBy('bucket_start_at', 'desc')
                        ->limit(10)
                        ->get();
                } else {
                    $aggregates = collect();
                }
                break;

            case 'download':
                if ($alertCandidate->subject_id) {
                    $aggregates = DownloadEventAggregate::where('event_type', $rule->event_type)
                        ->where('download_id', $alertCandidate->subject_id)
                        ->where('bucket_start_at', '>=', $windowStart)
                        ->orderBy('bucket_start_at', 'desc')
                        ->limit(10)
                        ->get();
                } else {
                    $aggregates = collect();
                }
                break;

            default:
                $aggregates = collect();
        }

        if ($aggregates->isNotEmpty()) {
            $context[] = "Recent event aggregates:";
            foreach ($aggregates->take(5) as $aggregate) {
                $context[] = "- {$aggregate->bucket_start_at->toDateTimeString()}: count={$aggregate->count}";
            }
        }

        return implode("\n", $context);
    }

    /**
     * Parse AI response into structured summary array.
     * 
     * @param string $aiResponse
     * @param AlertCandidate $alertCandidate
     * @return array
     */
    protected function parseAIResponse(string $aiResponse, AlertCandidate $alertCandidate): array
    {
        // Try to extract JSON from response
        $jsonMatch = [];
        if (preg_match('/\{[\s\S]*\}/', $aiResponse, $jsonMatch)) {
            try {
                $parsed = json_decode($jsonMatch[0], true);
                if ($parsed && is_array($parsed)) {
                    return [
                        'alert_candidate_id' => $alertCandidate->id,
                        'summary_text' => $parsed['summary_text'] ?? $this->generateDefaultSummary($alertCandidate),
                        'impact_summary' => $parsed['impact_summary'] ?? null,
                        'affected_scope' => $parsed['affected_scope'] ?? $this->generateAffectedScope($alertCandidate),
                        'severity' => $alertCandidate->severity,
                        'suggested_actions' => $parsed['suggested_actions'] ?? [],
                        'confidence_score' => 0.85,
                        'generated_at' => now(),
                    ];
                }
            } catch (\Exception $e) {
                Log::warning('[AlertSummaryService] Failed to parse AI JSON response', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fallback: treat entire response as summary_text
        return [
            'alert_candidate_id' => $alertCandidate->id,
            'summary_text' => trim($aiResponse) ?: $this->generateDefaultSummary($alertCandidate),
            'impact_summary' => null,
            'affected_scope' => $this->generateAffectedScope($alertCandidate),
            'severity' => $alertCandidate->severity,
            'suggested_actions' => [],
            'confidence_score' => 0.70, // Lower confidence for unparsed responses
            'generated_at' => now(),
        ];
    }

    /**
     * Generate stub summary when AI call fails.
     * 
     * @param AlertCandidate $alertCandidate
     * @return array
     */
    protected function generateStubSummary(AlertCandidate $alertCandidate): array
    {
        $rule = $alertCandidate->rule;

        $summaryText = sprintf(
            "Alert detected: %s. Observed %d events (threshold: %d) over %d minutes. " .
            "This is a %s severity issue affecting %s scope. " .
            "First detected at %s, occurred %d time(s).",
            $rule->name,
            $alertCandidate->observed_count,
            $alertCandidate->threshold_count,
            $alertCandidate->window_minutes,
            $alertCandidate->severity,
            $alertCandidate->scope,
            $alertCandidate->first_detected_at->toDateTimeString(),
            $alertCandidate->detection_count
        );

        $impactSummary = sprintf(
            "This alert affects %s scope. ",
            $alertCandidate->scope
        );
        if ($alertCandidate->subject_id) {
            $impactSummary .= "Subject ID: {$alertCandidate->subject_id}. ";
        }
        if ($alertCandidate->tenant_id) {
            $impactSummary .= "Tenant ID: {$alertCandidate->tenant_id}.";
        }

        $suggestedActions = [
            "Review the detection rule: {$rule->name}",
            "Check event aggregates for event type: {$rule->event_type}",
            "Investigate the root cause of the detected pattern",
        ];

        return [
            'alert_candidate_id' => $alertCandidate->id,
            'summary_text' => $summaryText,
            'impact_summary' => trim($impactSummary) ?: null,
            'affected_scope' => $this->generateAffectedScope($alertCandidate),
            'severity' => $alertCandidate->severity,
            'suggested_actions' => $suggestedActions,
            'confidence_score' => 0.50, // Lower confidence for stub summaries
            'generated_at' => now(),
        ];
    }

    /**
     * Generate default summary text.
     * 
     * @param AlertCandidate $alertCandidate
     * @return string
     */
    protected function generateDefaultSummary(AlertCandidate $alertCandidate): string
    {
        $rule = $alertCandidate->rule;
        return sprintf(
            "Alert: %s. %d events detected (threshold: %d) in %d minutes.",
            $rule->name,
            $alertCandidate->observed_count,
            $alertCandidate->threshold_count,
            $alertCandidate->window_minutes
        );
    }

    /**
     * Generate affected scope description.
     * 
     * @param AlertCandidate $alertCandidate
     * @return string|null
     */
    protected function generateAffectedScope(AlertCandidate $alertCandidate): ?string
    {
        switch ($alertCandidate->scope) {
            case 'global':
                return 'All tenants (global)';
            case 'tenant':
                return $alertCandidate->subject_id ? "Tenant {$alertCandidate->subject_id}" : 'Unknown tenant';
            case 'asset':
                return $alertCandidate->subject_id ? "Asset {$alertCandidate->subject_id}" : 'Unknown asset';
            case 'download':
                return $alertCandidate->subject_id ? "Download {$alertCandidate->subject_id}" : 'Unknown download';
            default:
                return null;
        }
    }
}
