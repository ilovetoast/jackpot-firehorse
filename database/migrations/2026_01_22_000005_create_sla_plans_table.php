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
        Schema::create('sla_plans', function (Blueprint $table) {
            $table->id();
            $table->string('plan_name')->unique(); // Maps to subscription plan name (free, starter, pro, enterprise)
            $table->integer('first_response_target_minutes')->nullable(); // Override from config
            $table->integer('resolution_target_minutes')->nullable(); // Override from config
            $table->json('support_hours')->nullable(); // Override from config
            $table->json('escalation_rules')->nullable(); // Override from config
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Indexes
            $table->index('plan_name');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sla_plans');
    }
};
