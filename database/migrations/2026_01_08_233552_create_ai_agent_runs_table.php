<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    /**
     * Run the migrations.
     * 
     * Creates the ai_agent_runs table for tracking AI agent executions.
     * This table is the primary unit of cost tracking and audit logging
     * for all AI operations in the system.
     */
    public function up(): void
    {
        Schema::create('ai_agent_runs', function (Blueprint $table) {
            $table->id();
            
            // Agent identification
            $table->string('agent_id')->index(); // References agent key from config/ai.php
            
            // Context and attribution
            $table->enum('triggering_context', ['system', 'tenant', 'user'])->index();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            
            // Task and model information
            $table->string('task_type')->index(); // e.g., 'support_ticket_summary' (from AITaskType enum)
            $table->string('model_used')->index(); // Actual model name used (e.g., 'gpt-4-turbo-preview')
            
            // Cost tracking
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->decimal('estimated_cost', 10, 6)->default(0); // In USD, 6 decimal precision for accuracy
            
            // Execution status
            $table->enum('status', ['success', 'failed'])->index();
            $table->text('error_message')->nullable();
            
            // Additional context (optional prompt/response logging if enabled)
            $table->json('metadata')->nullable(); // Stores prompt, response, and other context if logging enabled
            
            // Timing
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            
            $table->timestamps();
            
            // Composite indexes for common queries
            $table->index(['agent_id', 'status']);
            $table->index(['tenant_id', 'triggering_context']);
            $table->index(['task_type', 'status']);
        });

        // Add foreign key constraints if referenced tables exist
        if (Schema::hasTable('tenants')) {
            Schema::table('ai_agent_runs', function (Blueprint $table) {
                $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            });
        }
        if (Schema::hasTable('users')) {
            Schema::table('ai_agent_runs', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_agent_runs');
    }
};
