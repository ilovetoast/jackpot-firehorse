<?php

namespace App\Console\Commands\Automation;

use App\Jobs\Automation\DetectSLARiskJob;
use App\Models\Ticket;
use App\Enums\TicketStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Command to scan tickets for SLA risks.
 */
class ScanSLARisks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'automation:scan-sla-risks';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan open tickets for SLA breach risk';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Scanning tickets for SLA breach risk...');

        // Check if automation is enabled
        if (!config('automation.enabled', true) || !config('automation.triggers.sla_risk_detection.enabled', true)) {
            $this->warn('SLA risk detection is disabled.');
            return Command::SUCCESS;
        }

        $tickets = Ticket::whereIn('status', [
            TicketStatus::OPEN,
            TicketStatus::WAITING_ON_SUPPORT,
            TicketStatus::IN_PROGRESS,
        ])
            ->get();

        $this->info("Found {$tickets->count()} open tickets to scan.");

        $dispatched = 0;
        foreach ($tickets as $ticket) {
            if (config('automation.triggers.sla_risk_detection.async', true)) {
                DetectSLARiskJob::dispatch($ticket->id);
                $dispatched++;
            } else {
                // Process inline
                try {
                    $service = app(\App\Services\Automation\SLARiskDetectionService::class);
                    $service->analyzeTicketRisk($ticket);
                    $dispatched++;
                } catch (\Exception $e) {
                    Log::error('Failed to analyze SLA risk inline', [
                        'ticket_id' => $ticket->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->info("Processed {$dispatched} tickets.");

        return Command::SUCCESS;
    }
}
