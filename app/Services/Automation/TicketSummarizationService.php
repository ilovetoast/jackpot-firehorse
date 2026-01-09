<?php

namespace App\Services\Automation;

use App\Enums\AITaskType;
use App\Enums\LinkDesignation;
use App\Models\AIAgentRun;
use App\Models\Ticket;
use App\Models\TicketLink;
use App\Models\TicketMessage;
use App\Services\AIConfigService;
use App\Services\AIService;
use Illuminate\Support\Facades\Log;

/**
 * Ticket Summarization Service
 *
 * Handles AI-powered summarization of ticket conversations.
 * Triggered when:
 * - Message count >= threshold (configurable, default 5)
 * - Ticket status changes to waiting_on_support
 *
 * Outputs:
 * - Internal note with summary (is_internal=true, never visible to tenants)
 * - Links AI agent run to ticket via TicketLink
 */
class TicketSummarizationService
{
    public function __construct(
        protected AIService $aiService,
        protected AIConfigService $configService
    ) {
    }

    /**
     * Summarize a ticket conversation.
     *
     * @param Ticket $ticket
     * @return TicketMessage|null The created internal note, or null if summarization failed
     */
    public function summarizeTicket(Ticket $ticket): ?TicketMessage
    {
        // Check if automation is enabled
        $automationConfig = $this->configService->getAutomationConfig('ticket_summarization');
        if (!config('automation.enabled', true) || !($automationConfig['enabled'] ?? true)) {
            return null;
        }

        try {
            // Build prompt from ticket metadata and messages
            $prompt = $this->buildPrompt($ticket);

            // Execute AI agent
            $response = $this->aiService->executeAgent(
                'ticket_summarizer',
                AITaskType::SUPPORT_TICKET_SUMMARY,
                $prompt,
                [
                    'triggering_context' => 'system',
                    'tenant_id' => $ticket->tenant_id,
                ]
            );

            // Get system user
            $systemUser = $this->getSystemUser();

            // Create internal note with summary
            $summaryNote = TicketMessage::create([
                'ticket_id' => $ticket->id,
                'user_id' => $systemUser->id,
                'body' => '[AI Summary] ' . $response['text'],
                'is_internal' => true,
            ]);

            // Link AI agent run to ticket
            $agentRun = AIAgentRun::find($response['agent_run_id']);
            if ($agentRun) {
                TicketLink::create([
                    'ticket_id' => $ticket->id,
                    'linkable_type' => AIAgentRun::class,
                    'linkable_id' => $agentRun->id,
                    'link_type' => 'ai_agent_run',
                    'designation' => LinkDesignation::RELATED,
                    'metadata' => [
                        'task_type' => AITaskType::SUPPORT_TICKET_SUMMARY,
                        'cost' => $response['cost'],
                        'tokens_in' => $response['tokens_in'],
                        'tokens_out' => $response['tokens_out'],
                    ],
                ]);
            }

            return $summaryNote;
        } catch (\Exception $e) {
            Log::error('Failed to summarize ticket', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Build prompt for ticket summarization.
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
        $messages = $ticket->messages()->public()->with('user:id,first_name,last_name,email')->get();

        $messagesText = $messages->map(function ($message) {
            $userName = $message->user ? $message->user->name : 'Unknown User';
            return "{$userName}: {$message->body}";
        })->implode("\n\n");

        return <<<PROMPT
Summarize this support ticket conversation and extract key facts.

Ticket Subject: {$subject}
Ticket Description: {$description}

Conversation Messages:
{$messagesText}

Please provide a concise summary that includes:
1. Main issue or problem
2. Key facts and details
3. Current status or resolution progress
4. Any important context or background

Keep the summary clear and actionable for support staff.
PROMPT;
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
