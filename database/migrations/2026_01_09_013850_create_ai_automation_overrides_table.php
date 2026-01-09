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
     * Creates the ai_automation_overrides table for database-backed automation trigger overrides.
     * Allows administrators to override trigger enabled state and thresholds
     * without modifying code. Config files remain the source of truth for base definitions.
     */
    public function up(): void
    {
        Schema::create('ai_automation_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('trigger_key'); // References trigger key from config/automation.php
            $table->boolean('enabled')->nullable(); // Override enabled state (null = use config)
            $table->json('thresholds')->nullable(); // Override thresholds (null = use config)
            $table->string('environment')->nullable(); // Environment scope (null = all environments)
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
            
            // Indexes
            $table->index('trigger_key');
            $table->index('environment');
            // Note: Uniqueness is enforced in application logic (AIConfigService) since
            // MySQL unique constraints don't work well with NULL values in composite keys
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_automation_overrides');
    }
};
