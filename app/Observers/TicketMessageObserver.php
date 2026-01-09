<?php

namespace App\Observers;

use App\Jobs\Automation\SummarizeTicketJob;
use App\Models\TicketMessage;
use Illuminate\Support\Facades\Log;

/**
 * Ticket Message Observer
 *
 * Handles automation triggers for ticket message events.
 */
class TicketMessageObserver
{
    /**
     * Handle the TicketMessage "created" event.
     */
    public function created(TicketMessage $message): void
    {
        // Check if automation is enabled
        if (!config('automation.enabled', true)) {
            return;
        }

        // Check if ticket summarization is enabled
        if (!config('automation.triggers.ticket_summarization.enabled', true)) {
            return;
        }

        // Only count public messages (not internal notes) for threshold
        if ($message->is_internal) {
            return;
        }

        $ticket = $message->ticket;
        if (!$ticket) {
            return;
        }

        // Count public messages
        $publicMessageCount = $ticket->messages()->public()->count();
        $threshold = config('automation.triggers.ticket_summarization.message_threshold', 5);

        // If message count >= threshold, trigger summarization
        if ($publicMessageCount >= $threshold) {
            // Check if we've already summarized recently (prevent duplicate summaries)
            $recentSummary = $ticket->messages()
                ->internal()
                ->where('body', 'like', '%[AI Summary]%')
                ->where('created_at', '>=', now()->subHours(1))
                ->exists();

            if (!$recentSummary) {
                SummarizeTicketJob::dispatch($ticket->id);
            }
        }
    }
}
