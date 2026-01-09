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
     * Creates the ai_tenant_budgets table for tenant-level budget structure.
     * This is preparation only - enforcement logic is not implemented in Phase 9.
     * Structure exists to enable future tenant AI cost controls.
     */
    public function up(): void
    {
        Schema::create('ai_tenant_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->decimal('amount', 10, 2); // Budget amount in USD
            $table->enum('period', ['monthly'])->default('monthly');
            $table->integer('warning_threshold_percent')->default(80);
            $table->boolean('hard_limit_enabled')->default(false);
            $table->string('environment')->nullable()->index(); // Environment scope (null = all)
            $table->timestamps();
            
            // Indexes
            $table->index('tenant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_tenant_budgets');
    }
};
