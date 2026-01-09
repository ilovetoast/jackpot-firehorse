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
     * Creates the ai_agent_overrides table for database-backed agent configuration overrides.
     * Allows administrators to override agent active state and default model selection
     * without modifying code. Config files remain the source of truth for base definitions.
     */
    public function up(): void
    {
        Schema::create('ai_agent_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('agent_id'); // References agent key from config/ai.php
            $table->boolean('active')->nullable(); // Override active state (null = use config)
            $table->string('default_model')->nullable(); // Override default model (null = use config)
            $table->string('environment')->nullable(); // Environment scope (null = all environments)
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
            
            // Indexes
            $table->index('agent_id');
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
        Schema::dropIfExists('ai_agent_overrides');
    }
};
