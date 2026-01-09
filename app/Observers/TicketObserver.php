<?php

namespace App\Observers;

use App\Enums\TicketStatus;
use App\Enums\TicketTeam;
use App\Jobs\Automation\ClassifyTicketJob;
use App\Jobs\Automation\DetectDuplicateJob;
use App\Jobs\Automation\SummarizeTicketJob;
use App\Models\Ticket;
use App\Services\Automation\DuplicateTicketDetectionService;
use App\Services\Automation\TicketClassificationService;
use Illuminate\Support\Facades\Log;

/**
 * Ticket Observer
 *
 * Handles automation triggers for ticket events.
 */
class TicketObserver
{
    /**
     * Handle the Ticket "created" event.
     */
    public function created(Ticket $ticket): void
    {
        // Check if automation is enabled
        if (!config('automation.enabled', true)) {
            return;
        }

        // Trigger classification if enabled
        if (config('automation.triggers.ticket_classification.enabled', true)
            && config('automation.triggers.ticket_classification.on_creation', true)) {
            if (config('automation.triggers.ticket_classification.async', false)) {
                ClassifyTicketJob::dispatch($ticket->id);
            } else {
                try {
                    $service = app(TicketClassificationService::class);
                    $service->classifyTicket($ticket);
                } catch (\Exception $e) {
                    Log::error('Failed to classify ticket on creation', [
                        'ticket_id' => $ticket->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Trigger duplicate detection if enabled
        if (config('automation.triggers.duplicate_detection.enabled', true)) {
            try {
                if (config('automation.triggers.duplicate_detection.async', false)) {
                    DetectDuplicateJob::dispatch($ticket->id);
                } else {
                    $service = app(DuplicateTicketDetectionService::class);
                    $service->detectDuplicates($ticket);
                }
            } catch (\Exception $e) {
                Log::error('Failed to detect duplicates on ticket creation', [
                    'ticket_id' => $ticket->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Handle the Ticket "updated" event.
     */
    public function updated(Ticket $ticket): void
    {
        // Check if automation is enabled
        if (!config('automation.enabled', true)) {
            return;
        }

        // If status changed to waiting_on_support, trigger summarization
        if ($ticket->isDirty('status') && $ticket->status === TicketStatus::WAITING_ON_SUPPORT) {
            if (config('automation.triggers.ticket_summarization.enabled', true)) {
                SummarizeTicketJob::dispatch($ticket->id);
            }
        }

        // If assigned_team changed to engineering, trigger classification
        if ($ticket->isDirty('assigned_team') && $ticket->assigned_team === TicketTeam::ENGINEERING) {
            if (config('automation.triggers.ticket_classification.enabled', true)
                && config('automation.triggers.ticket_classification.on_escalation', true)) {
                if (config('automation.triggers.ticket_classification.async', false)) {
                    ClassifyTicketJob::dispatch($ticket->id);
                } else {
                    try {
                        $service = app(TicketClassificationService::class);
                        $service->classifyTicket($ticket);
                    } catch (\Exception $e) {
                        Log::error('Failed to classify ticket on escalation', [
                            'ticket_id' => $ticket->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }
    }
}
