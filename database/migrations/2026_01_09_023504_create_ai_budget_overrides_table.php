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
     * Creates the ai_budget_overrides table for database-backed budget overrides.
     * Allows administrators to override budget settings without modifying code.
     */
    public function up(): void
    {
        Schema::create('ai_budget_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained('ai_budgets')->onDelete('cascade');
            $table->decimal('amount', 10, 2)->nullable(); // Override amount (null = use config)
            $table->integer('warning_threshold_percent')->nullable(); // Override threshold (null = use config)
            $table->boolean('hard_limit_enabled')->nullable(); // Override hard limit (null = use config)
            $table->string('environment')->nullable()->index(); // Environment scope (null = all)
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
            
            // Indexes
            $table->index('budget_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_budget_overrides');
    }
};
