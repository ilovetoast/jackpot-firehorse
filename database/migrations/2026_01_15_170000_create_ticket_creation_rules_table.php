<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🔒 Phase 5A Step 2 — Automatic Ticket Creation Rules
 * 
 * Defines when alert candidates should automatically generate support tickets.
 * Phase 4 and Phase 5A Step 1 are LOCKED - this phase consumes alerts and tickets only.
 * 
 * Create Ticket Creation Rules Table
 * 
 * Stores rules that determine when to automatically create support tickets from alert candidates.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ticket_creation_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained('detection_rules')->onDelete('cascade')->comment('Link to detection rule');
            $table->enum('min_severity', ['warning', 'critical'])->default('critical')->comment('Minimum severity level to trigger auto-ticket creation');
            $table->unsignedInteger('required_detection_count')->default(1)->comment('Minimum detection_count required before creating ticket');
            $table->boolean('auto_create')->default(true)->comment('Whether to automatically create tickets when rule matches');
            $table->boolean('enabled')->default(false)->comment('Whether this rule is active');
            $table->timestamps();

            // Indexes for efficient querying
            $table->index('rule_id');
            $table->index(['enabled', 'auto_create']);
            $table->index('min_severity');

            // One rule per detection rule
            $table->unique('rule_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_creation_rules');
    }
};
