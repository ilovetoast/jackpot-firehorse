<?php

namespace App\Services;

use App\Services\Tickets\Adapters\NullTicketAdapter;
use App\Services\Tickets\Contracts\ExternalTicketAdapter;
use Illuminate\Support\Facades\Log;

/**
 * 🔒 Phase 5A Step 3 — External Ticket Service
 * 
 * Resolves and manages external ticket system adapters.
 * 
 * ExternalTicketService
 * 
 * Purpose:
 * - Resolves appropriate adapter based on configuration
 * - Provides single point of access for external ticket operations
 * - Handles adapter instantiation and caching
 * 
 * Configuration:
 * - tickets.driver: 'null' | 'zendesk' | 'jira' | 'linear'
 * - Defaults to 'null' (NullTicketAdapter)
 * 
 * Adapter Resolution:
 * 1. Reads tickets.driver from config
 * 2. Maps driver to adapter class
 * 3. Returns adapter instance
 * 4. Falls back to NullTicketAdapter if driver not found/configured
 * 
 * Phase 5A Step 3: Only null adapter implemented
 * - Zendesk, Jira, Linear adapters will be implemented in future steps
 * - Adapter resolution logic is ready for future implementations
 */
class ExternalTicketService
{
    protected ?ExternalTicketAdapter $adapter = null;

    /**
     * Get the configured external ticket adapter.
     * 
     * @return ExternalTicketAdapter
     */
    public function getAdapter(): ExternalTicketAdapter
    {
        if ($this->adapter !== null) {
            return $this->adapter;
        }

        $driver = config('tickets.driver', 'null');

        $this->adapter = $this->resolveAdapter($driver);

        Log::debug('[ExternalTicketService] Resolved external ticket adapter', [
            'driver' => $driver,
            'adapter_name' => $this->adapter->getAdapterName(),
        ]);

        return $this->adapter;
    }

    /**
     * Resolve adapter class based on driver name.
     * 
     * @param string $driver Driver name from config
     * @return ExternalTicketAdapter
     */
    protected function resolveAdapter(string $driver): ExternalTicketAdapter
    {
        // Map driver names to adapter classes
        $adapters = [
            'null' => NullTicketAdapter::class,
            // Future implementations:
            // 'zendesk' => ZendeskTicketAdapter::class,
            // 'jira' => JiraTicketAdapter::class,
            // 'linear' => LinearTicketAdapter::class,
        ];

        $adapterClass = $adapters[$driver] ?? NullTicketAdapter::class;

        if (!isset($adapters[$driver])) {
            Log::warning('[ExternalTicketService] Unknown driver, falling back to null adapter', [
                'driver' => $driver,
                'available_drivers' => array_keys($adapters),
            ]);
        }

        return app($adapterClass);
    }

    /**
     * Create a ticket in the external system.
     * 
     * @param \App\Models\SupportTicket $ticket
     * @return \App\Services\Tickets\Contracts\ExternalTicketResult
     */
    public function createTicket(\App\Models\SupportTicket $ticket): \App\Services\Tickets\Contracts\ExternalTicketResult
    {
        return $this->getAdapter()->createTicket($ticket);
    }

    /**
     * Update ticket status in the external system.
     * 
     * @param \App\Models\SupportTicket $ticket
     * @return void
     */
    public function updateTicketStatus(\App\Models\SupportTicket $ticket): void
    {
        $this->getAdapter()->updateTicketStatus($ticket);
    }

    /**
     * Add a comment to the ticket in the external system.
     * 
     * @param \App\Models\SupportTicket $ticket
     * @param string $comment
     * @return void
     */
    public function addComment(\App\Models\SupportTicket $ticket, string $comment): void
    {
        $this->getAdapter()->addComment($ticket, $comment);
    }
}
