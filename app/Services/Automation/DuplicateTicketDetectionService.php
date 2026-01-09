<?php

namespace App\Services\Automation;

use App\Enums\AITaskType;
use App\Enums\AutomationSuggestionType;
use App\Enums\LinkDesignation;
use App\Models\AIAgentRun;
use App\Models\AITicketSuggestion;
use App\Models\Ticket;
use App\Models\TicketLink;
use App\Services\AIService;
use Illuminate\Support\Facades\Log;

/**
 * Duplicate Ticket Detection Service
 *
 * Handles AI-powered duplicate ticket detection.
 * Triggered on:
 * - Ticket creation
 * - Ticket escalation
 *
 * Outputs:
 * - Suggestion with potential duplicate ticket IDs
 * - Creates TicketLink with designation=duplicate (pending confirmation)
 * - Never auto-links tickets (human confirmation required)
 */
class DuplicateTicketDetectionService
{
    public function __construct(
        protected AIService $aiService
    ) {
    }

    /**
     * Detect duplicate tickets for a given ticket.
     *
     * @param Ticket $ticket
     * @return AITicketSuggestion|null The created suggestion, or null if detection failed
     */
    public function detectDuplicates(Ticket $ticket): ?AITicketSuggestion
    {
        // Check if automation is enabled
        if (!config('automation.enabled', true) || !config('automation.triggers.duplicate_detection.enabled', true)) {
            return null;
        }

        try {
            // Find recent tickets to compare against
            $recentTickets = $this->findRecentTickets($ticket);

            if ($recentTickets->isEmpty()) {
                return null; // No recent tickets to compare
            }

            // Build prompt with ticket context and recent tickets
            $prompt = $this->buildPrompt($ticket, $recentTickets);

            // Execute AI agent
            $response = $this->aiService->executeAgent(
                'duplicate_detector',
                AITaskType::DUPLICATE_TICKET_DETECTION,
                $prompt,
                [
                    'triggering_context' => 'system',
                    'tenant_id' => $ticket->tenant_id,
                ]
            );

            // Parse AI response
            $duplicates = $this->parseDuplicateResponse($response['text']);

            if (empty($duplicates['ticket_ids'])) {
                return null; // No duplicates detected
            }

            // Get agent run
            $agentRun = AIAgentRun::find($response['agent_run_id']);

            // Create suggestion
            $suggestion = AITicketSuggestion::create([
                'ticket_id' => $ticket->id,
                'suggestion_type' => AutomationSuggestionType::DUPLICATE,
                'suggested_value' => [
                    'duplicate_ticket_ids' => $duplicates['ticket_ids'],
                    'confidence' => $duplicates['confidence'] ?? 0.5,
                    'reasoning' => $duplicates['reasoning'] ?? null,
                ],
                'confidence_score' => $duplicates['confidence'] ?? 0.5,
                'ai_agent_run_id' => $agentRun?->id,
                'metadata' => [
                    'task_type' => AITaskType::DUPLICATE_TICKET_DETECTION,
                    'cost' => $response['cost'],
                    'tokens_in' => $response['tokens_in'],
                    'tokens_out' => $response['tokens_out'],
                ],
            ]);

            // Create pending ticket links (will be confirmed if suggestion is accepted)
            foreach ($duplicates['ticket_ids'] as $duplicateTicketId) {
                $duplicateTicket = Ticket::find($duplicateTicketId);
                if ($duplicateTicket) {
                    // Create link with pending confirmation
                    TicketLink::create([
                        'ticket_id' => $ticket->id,
                        'linkable_type' => Ticket::class,
                        'linkable_id' => $duplicateTicketId,
                        'link_type' => 'ticket',
                        'designation' => LinkDesignation::DUPLICATE,
                        'metadata' => [
                            'suggestion_id' => $suggestion->id,
                            'pending_confirmation' => true,
                        ],
                    ]);
                }
            }

            return $suggestion;
        } catch (\Exception $e) {
            Log::error('Failed to detect duplicate tickets', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Find recent tickets to compare against.
     *
     * @param Ticket $ticket
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function findRecentTickets(Ticket $ticket): \Illuminate\Database\Eloquent\Collection
    {
        // Look for tickets created in the last 30 days
        $recentCutoff = now()->subDays(30);

        return Ticket::where('id', '!=', $ticket->id)
            ->where('created_at', '>=', $recentCutoff)
            ->where('tenant_id', $ticket->tenant_id) // Same tenant only
            ->where('type', $ticket->type) // Same type
            ->with(['messages' => function ($query) {
                $query->public()->orderBy('created_at', 'desc')->limit(5);
            }])
            ->orderBy('created_at', 'desc')
            ->limit(20) // Compare against up to 20 recent tickets
            ->get();
    }

    /**
     * Build prompt for duplicate detection.
     *
     * @param Ticket $ticket
     * @param \Illuminate\Database\Eloquent\Collection $recentTickets
     * @return string
     */
    protected function buildPrompt(Ticket $ticket, $recentTickets): string
    {
        $metadata = $ticket->metadata ?? [];
        $subject = $metadata['subject'] ?? 'No subject';
        $description = $metadata['description'] ?? 'No description';

        // Get ticket messages
        $messages = $ticket->messages()->public()->get();
        $messagesText = $messages->map(fn($m) => $m->body)->implode("\n\n");

        // Build recent tickets context
        $recentTicketsText = $recentTickets->map(function ($t) {
            $tMetadata = $t->metadata ?? [];
            $tSubject = $tMetadata['subject'] ?? 'No subject';
            $tDescription = $tMetadata['description'] ?? 'No description';
            return "Ticket #{$t->ticket_number} (ID: {$t->id}):\nSubject: {$tSubject}\nDescription: {$tDescription}";
        })->implode("\n\n---\n\n");

        return <<<PROMPT
Analyze this ticket and determine if it's a duplicate of any recent tickets.

Current Ticket:
Ticket #{$ticket->ticket_number} (ID: {$ticket->id})
Subject: {$subject}
Description: {$description}

Conversation Messages:
{$messagesText}

Recent Tickets to Compare:
{$recentTicketsText}

Please analyze if the current ticket is a duplicate of any recent tickets.
Consider:
1. Similar subject matter
2. Similar problem description
3. Similar conversation context
4. Same issue or concern

Provide a JSON response:
{
    "ticket_ids": [array of ticket IDs that are potential duplicates, empty array if none],
    "confidence": 0.0 to 1.0,
    "reasoning": "brief explanation"
}

Only include ticket IDs if you're confident they are duplicates (confidence >= 0.7).
PROMPT;
    }

    /**
     * Parse AI response for duplicate ticket IDs.
     *
     * @param string $responseText
     * @return array
     */
    protected function parseDuplicateResponse(string $responseText): array
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

        Log::warning('Failed to parse duplicate detection response as JSON', [
            'response' => $responseText,
        ]);

        return ['ticket_ids' => [], 'confidence' => 0.0, 'reasoning' => 'Unable to parse AI response'];
    }
}
