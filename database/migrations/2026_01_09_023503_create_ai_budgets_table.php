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
     * Creates the ai_budgets table for AI budget definitions.
     * Budgets can be system-wide, per-agent, or per-task type.
     * Budgets are defined in config/ai.php with optional database overrides.
     */
    public function up(): void
    {
        Schema::create('ai_budgets', function (Blueprint $table) {
            $table->id();
            $table->enum('budget_type', ['system', 'agent', 'task_type'])->index();
            $table->string('scope_key')->nullable()->index(); // agent_id or task_type, null for system
            $table->decimal('amount', 10, 2); // Budget amount in USD
            $table->enum('period', ['monthly'])->default('monthly');
            $table->integer('warning_threshold_percent')->default(80); // Warn at 80% by default
            $table->boolean('hard_limit_enabled')->default(false); // Soft limit by default
            $table->string('environment')->nullable()->index(); // Environment scope (null = all)
            $table->timestamps();
            
            // Indexes
            $table->index('period');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_budgets');
    }
};
