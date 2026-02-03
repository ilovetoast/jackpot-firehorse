<?php

namespace App\Services\Automation;

use App\Enums\AITaskType;
use App\Enums\AutomationSuggestionType;
use App\Models\AIAgentRun;
use App\Models\AITicketSuggestion;
use App\Models\Ticket;
use App\Services\AIService;
use Illuminate\Support\Facades\Log;

/**
 * Ticket Classification Service
 *
 * Handles AI-powered classification suggestions for tickets.
 * Triggered on:
 * - Ticket creation
 * - Ticket escalation to engineering
 *
 * Outputs:
 * - Suggestions stored in AITicketSuggestion table AND ticket metadata
 * - Never auto-applies suggestions (human approval required)
 */
class TicketClassificationService
{
    public function __construct(
        protected AIService $aiService
    ) {
    }

    /**
     * Classify a ticket and suggest category, severity, component.
     *
     * @param Ticket $ticket
     * @return AITicketSuggestion|null The created suggestion, or null if classification failed
     */
    public function classifyTicket(Ticket $ticket): ?AITicketSuggestion
    {
        // Check if automation is enabled
        if (!config('automation.enabled', true) || !config('automation.triggers.ticket_classification.enabled', true)) {
            return null;
        }

        // Only classify tenant tickets or internal engineering tickets
        if ($ticket->type->value === 'tenant_internal') {
            return null;
        }

        try {
            // Build prompt from ticket data
            $prompt = $this->buildPrompt($ticket);

            // Execute AI agent
            $response = $this->aiService->executeAgent(
                'ticket_classifier',
                AITaskType::TICKET_CLASSIFICATION,
                $prompt,
                [
                    'triggering_context' => 'system',
                    'tenant_id' => $ticket->tenant_id,
                ]
            );

            // Parse AI response for suggestions
            $suggestions = $this->parseClassificationResponse($response['text']);

            if (empty($suggestions)) {
                Log::warning('AI classification returned no suggestions', [
                    'ticket_id' => $ticket->id,
                    'response' => $response['text'],
                ]);
                return null;
            }

            // Get agent run
            $agentRun = AIAgentRun::find($response['agent_run_id']);

            // Store suggestion in database
            $suggestion = AITicketSuggestion::create([
                'ticket_id' => $ticket->id,
                'suggestion_type' => AutomationSuggestionType::CLASSIFICATION,
                'suggested_value' => $suggestions,
                'confidence_score' => $suggestions['confidence'] ?? 0.5,
                'ai_agent_run_id' => $agentRun?->id,
                'metadata' => [
                    'task_type' => AITaskType::TICKET_CLASSIFICATION,
                    'cost' => $response['cost'],
                    'tokens_in' => $response['tokens_in'],
                    'tokens_out' => $response['tokens_out'],
                ],
            ]);

            // Also store in ticket metadata for quick access
            $metadata = $ticket->metadata ?? [];
            $metadata['ai_classification_suggestion'] = [
                'category' => $suggestions['category'] ?? null,
                'severity' => $suggestions['severity'] ?? null,
                'component' => $suggestions['component'] ?? null,
                'environment' => $suggestions['environment'] ?? null,
                'confidence' => $suggestions['confidence'] ?? null,
                'suggestion_id' => $suggestion->id,
            ];
            $ticket->update(['metadata' => $metadata]);

            return $suggestion;
        } catch (\Exception $e) {
            Log::error('Failed to classify ticket', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Build prompt for ticket classification.
     *
     * @param Ticket $ticket
     * @return string
     */
    protected function buildPrompt(Ticket $ticket): string
    {
        $metadata = $ticket->metadata ?? [];
        $subject = $metadata['subject'] ?? 'No subject';
        $description = $metadata['description'] ?? 'No description';

        // Get public messages (exclude internal notes)
        $messages = $ticket->messages()->public()->get();
        $messagesText = $messages->map(fn($m) => $m->body)->implode("\n\n");

        $availableCategories = implode(', ', \App\Enums\TicketCategory::values());
        $availableSeverities = implode(', ', \App\Enums\TicketSeverity::values());
        $availableComponents = implode(', ', \App\Enums\TicketComponent::values());
        $availableEnvironments = implode(', ', \App\Enums\TicketEnvironment::values());

        $isEngineering = $ticket->assigned_team?->value === 'engineering' || $ticket->type->value === 'internal';

        return <<<PROMPT
Analyze this support ticket and suggest appropriate classification.

Ticket Subject: {$subject}
Ticket Description: {$description}

Conversation Messages:
{$messagesText}

Available Categories: {$availableCategories}
Available Severities: {$availableSeverities}
Available Components: {$availableComponents}
Available Environments: {$availableEnvironments}

Is Engineering Ticket: {($isEngineering ? 'Yes' : 'No')}

Please provide a JSON response with the following structure:
{
    "category": "one of the available categories",
    "severity": "one of the available severities (only if engineering ticket, otherwise null)",
    "component": "one of the available components (only if engineering ticket, otherwise null)",
    "environment": "one of the available environments (only if engineering ticket, otherwise null)",
    "confidence": 0.0 to 1.0,
    "reasoning": "brief explanation of the classification"
}

Only suggest severity, component, and environment if this is an engineering ticket.
PROMPT;
    }

    /**
     * Parse AI response and extract classification suggestions.
     *
     * @param string $responseText
     * @return array
     */
    protected function parseClassificationResponse(string $responseText): array
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

        Log::warning('Failed to parse classification response as JSON', [
            'response' => $responseText,
        ]);

        return [];
    }
}
