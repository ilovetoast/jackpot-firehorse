<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ticket_sla_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->unique()->constrained()->onDelete('cascade'); // One SLA state per ticket
            $table->string('sla_plan_id'); // References plan name (free, starter, pro, enterprise)
            $table->integer('first_response_target_minutes'); // Target from SLA plan
            $table->integer('resolution_target_minutes')->nullable(); // Target from SLA plan (nullable)
            $table->timestamp('first_response_deadline')->nullable(); // Calculated deadline
            $table->timestamp('resolution_deadline')->nullable(); // Calculated deadline
            $table->timestamp('first_response_at')->nullable(); // Actual first response time
            $table->timestamp('resolved_at')->nullable(); // Actual resolution time
            $table->boolean('breached_first_response')->default(false); // Breach flag
            $table->boolean('breached_resolution')->default(false); // Breach flag
            $table->timestamp('paused_at')->nullable(); // When timer was paused
            $table->integer('total_paused_minutes')->default(0); // Cumulative paused time
            $table->string('last_status_before_pause')->nullable(); // Status when paused
            $table->timestamps();

            // Indexes
            $table->index('first_response_deadline');
            $table->index('resolution_deadline');
            $table->index('breached_first_response');
            $table->index('breached_resolution');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_sla_states');
    }
};
