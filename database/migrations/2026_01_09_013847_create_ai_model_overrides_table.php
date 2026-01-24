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
     * Creates the ai_model_overrides table for database-backed model configuration overrides.
     * Allows administrators to override model active state and default model selection
     * without modifying code. Config files remain the source of truth for base definitions.
     */
    public function up(): void
    {
        if (Schema::hasTable('ai_model_overrides')) {
            return;
        }

        Schema::create('ai_model_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('model_key'); // References model key from config/ai.php
            $table->boolean('active')->nullable(); // Override active state (null = use config)
            $table->json('default_for_tasks')->nullable(); // Array of task types that should use this model by default
            $table->string('environment')->nullable(); // Environment scope (null = all environments)
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index('model_key');
            $table->index('environment');
            // Note: Uniqueness is enforced in application logic (AIConfigService) since
            // MySQL unique constraints don't work well with NULL values in composite keys
        });
        
        // Add foreign keys only if users table exists
        if (Schema::hasTable('users')) {
            Schema::table('ai_model_overrides', function (Blueprint $table) {
                $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
                $table->foreign('updated_by_user_id')->references('id')->on('users')->onDelete('set null');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_model_overrides');
    }
};
