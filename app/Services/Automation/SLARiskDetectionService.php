<?php

namespace App\Services\Automation;

use App\Enums\AITaskType;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\AIService;
use Illuminate\Support\Facades\Log;

/**
 * SLA Risk Detection Service
 *
 * Handles AI-powered SLA risk detection for tickets.
 * Scheduled hourly scan of open tickets.
 *
 * Outputs:
 * - Risk flag stored in ticket metadata (internal only)
 * - Internal note with reasoning if high risk detected
 */
class SLARiskDetectionService
{
    public function __construct(
        protected AIService $aiService
    ) {
    }

    /**
     * Scan all open tickets for SLA breach risk.
     *
     * @return int Number of tickets scanned
     */
    public function scanAllOpenTickets(): int
    {
        // Check if automation is enabled
        if (!config('automation.enabled', true) || !config('automation.triggers.sla_risk_detection.enabled', true)) {
            return 0;
        }

        $tickets = Ticket::whereIn('status', [
            TicketStatus::OPEN,
            TicketStatus::WAITING_ON_SUPPORT,
            TicketStatus::IN_PROGRESS,
        ])
            ->with(['slaState', 'messages' => function ($query) {
                $query->public()->orderBy('created_at', 'desc');
            }])
            ->get();

        $scanned = 0;
        foreach ($tickets as $ticket) {
            try {
                $this->analyzeTicketRisk($ticket);
                $scanned++;
            } catch (\Exception $e) {
                Log::error('Failed to analyze SLA risk for ticket', [
                    'ticket_id' => $ticket->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $scanned;
    }

    /**
     * Analyze a single ticket for SLA breach risk.
     *
     * @param Ticket $ticket
     * @return void
     */
    public function analyzeTicketRisk(Ticket $ticket): void
    {
        try {
            // Build prompt with ticket context and SLA state
            $prompt = $this->buildPrompt($ticket);

            // Execute AI agent
            $response = $this->aiService->executeAgent(
                'sla_risk_analyzer',
                AITaskType::SLA_RISK_DETECTION,
                $prompt,
                [
                    'triggering_context' => 'system',
                    'tenant_id' => $ticket->tenant_id,
                ]
            );

            // Parse AI response
            $riskData = $this->parseRiskResponse($response['text']);

            // Store risk flag in ticket metadata
            $metadata = $ticket->metadata ?? [];
            $metadata['sla_risk'] = [
                'level' => $riskData['level'] ?? 'low', // low, medium, high
                'reasoning' => $riskData['reasoning'] ?? null,
                'detected_at' => now()->toIso8601String(),
            ];
            $ticket->update(['metadata' => $metadata]);

            // Create internal note if high risk
            if (($riskData['level'] ?? 'low') === 'high') {
                $systemUser = $this->getSystemUser();
                TicketMessage::create([
                    'ticket_id' => $ticket->id,
                    'user_id' => $systemUser->id,
                    'body' => '[AI SLA Risk Alert] ' . ($riskData['reasoning'] ?? 'High risk of SLA breach detected'),
                    'is_internal' => true,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to analyze SLA risk', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Build prompt for SLA risk analysis.
     *
     * @param Ticket $ticket
     * @return string
     */
    protected function buildPrompt(Ticket $ticket): string
    {
        $slaState = $ticket->slaState;
        $messages = $ticket->messages()->public()->orderBy('created_at', 'desc')->get();
        $messageVelocity = $this->calculateMessageVelocity($messages);
        $statusHistory = $this->getStatusHistory($ticket);

        $slaInfo = 'No SLA assigned';
        if ($slaState) {
            $slaInfo = sprintf(
                "First Response Deadline: %s\nResolution Deadline: %s\nFirst Response Target: %d minutes\nResolution Target: %d minutes",
                $slaState->first_response_deadline?->toIso8601String() ?? 'N/A',
                $slaState->resolution_deadline?->toIso8601String() ?? 'N/A',
                $slaState->first_response_target_minutes ?? 0,
                $slaState->resolution_target_minutes ?? 0
            );
        }

        return <<<PROMPT
Analyze this ticket for SLA breach risk.

Ticket Status: {$ticket->status->value}
Ticket Created: {$ticket->created_at->toIso8601String()}
Current Time: {now()->toIso8601String()}

SLA Information:
{$slaInfo}

Message Velocity: {$messageVelocity} messages per hour (last 24 hours)
Status Changes: {$statusHistory}

Please analyze:
1. Message velocity trends
2. Status churn patterns
3. Historical resolution patterns
4. Time remaining until SLA deadlines

Provide a JSON response:
{
    "level": "low|medium|high",
    "reasoning": "brief explanation",
    "factors": ["factor1", "factor2"]
}
PROMPT;
    }

    /**
     * Calculate message velocity (messages per hour in last 24 hours).
     *
     * @param \Illuminate\Database\Eloquent\Collection $messages
     * @return float
     */
    protected function calculateMessageVelocity($messages): float
    {
        $last24Hours = now()->subHours(24);
        $recentMessages = $messages->filter(fn($m) => $m->created_at >= $last24Hours);

        $hours = 24;
        if ($recentMessages->isNotEmpty()) {
            $firstMessage = $recentMessages->first()->created_at;
            $hours = max(1, now()->diffInHours($firstMessage));
        }

        return round($recentMessages->count() / $hours, 2);
    }

    /**
     * Get status change history summary.
     *
     * @param Ticket $ticket
     * @return string
     */
    protected function getStatusHistory(Ticket $ticket): string
    {
        // This would ideally come from activity events
        // For now, return basic info
        return sprintf(
            'Created %s, Last updated %s',
            $ticket->created_at->diffForHumans(),
            $ticket->updated_at->diffForHumans()
        );
    }

    /**
     * Parse AI response for risk data.
     *
     * @param string $responseText
     * @return array
     */
    protected function parseRiskResponse(string $responseText): array
    {
        // Try to extract JSON from response
        $jsonMatch = [];
        if (preg_match('/\{[^}]+\}/s', $responseText, $jsonMatch)) {
            $data = json_decode($jsonMatch[0], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                return $data;
            }
        }

        // Fallback: try to parse the entire response as JSON
        $data = json_decode($responseText, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            return $data;
        }

        Log::warning('Failed to parse SLA risk response as JSON', [
            'response' => $responseText,
        ]);

        return ['level' => 'low', 'reasoning' => 'Unable to parse AI response'];
    }

    /**
     * Get system user for automation actions.
     *
     * @return \App\Models\User
     */
    protected function getSystemUser(): \App\Models\User
    {
        $systemUser = \App\Models\User::where('email', 'system@internal')->first();
        if (!$systemUser) {
            Log::warning('System user not found, using user ID 1 as fallback');
            $systemUser = \App\Models\User::find(1);
        }

        return $systemUser;
    }
}
