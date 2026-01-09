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
     * Creates the ai_budget_usage table for tracking budget consumption.
     * Tracks usage per budget per period (monthly).
     */
    public function up(): void
    {
        Schema::create('ai_budget_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained('ai_budgets')->onDelete('cascade');
            $table->date('period_start'); // Start of budget period (e.g., 2026-01-01)
            $table->date('period_end'); // End of budget period (e.g., 2026-01-31)
            $table->decimal('amount_used', 10, 2)->default(0); // Amount used in this period
            $table->timestamp('last_updated_at')->nullable(); // Last time usage was updated
            $table->timestamps();
            
            // Unique constraint: one usage record per budget per period
            $table->unique(['budget_id', 'period_start']);
            
            // Indexes
            $table->index('budget_id');
            $table->index('period_start');
            $table->index('period_end');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_budget_usage');
    }
};
