<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🔒 Phase 5A Step 1 — Support Ticket Foundations
 * 
 * Creates durable, auditable link between alert candidates and support tickets.
 * Phase 4 is LOCKED - this phase consumes alerts only, does not modify them.
 * 
 * Create Support Tickets Table
 * 
 * Stores support tickets that can be linked to alert candidates.
 * Tickets can be created from alerts (system) or manually.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_candidate_id')->nullable()->constrained('alert_candidates')->onDelete('set null')->comment('Link to alert candidate if ticket created from alert');
            $table->string('summary')->comment('Brief summary of the ticket');
            $table->text('description')->nullable()->comment('Detailed description of the issue');
            $table->enum('severity', ['info', 'warning', 'critical'])->default('warning')->comment('Severity level (copied from alert if present)');
            $table->enum('status', ['open', 'in_progress', 'resolved', 'closed'])->default('open')->comment('Ticket status');
            $table->enum('source', ['system', 'manual'])->default('system')->comment('How the ticket was created');
            $table->string('external_reference')->nullable()->comment('Reference to external ticket system (Zendesk, Linear, Jira, etc.)');
            $table->timestamps();

            // Indexes for efficient querying
            $table->index('alert_candidate_id');
            $table->index('status');
            $table->index('severity');
            $table->index('source');
            $table->index('external_reference');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
