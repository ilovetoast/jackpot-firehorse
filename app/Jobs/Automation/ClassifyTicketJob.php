<?php

namespace App\Jobs\Automation;

use App\Models\Ticket;
use App\Services\Automation\TicketClassificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queue job for async ticket classification (if configured async).
 */
class ClassifyTicketJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $ticketId
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(TicketClassificationService $service): void
    {
        $ticket = Ticket::find($this->ticketId);

        if (!$ticket) {
            Log::warning('Ticket not found for classification', [
                'ticket_id' => $this->ticketId,
            ]);
            return;
        }

        try {
            $service->classifyTicket($ticket);
        } catch (\Exception $e) {
            Log::error('Failed to classify ticket in job', [
                'ticket_id' => $this->ticketId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Ticket classification job failed', [
            'ticket_id' => $this->ticketId,
            'error' => $exception->getMessage(),
        ]);
    }
}
